<?php

namespace Kicol\FullFlow\Webhook;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência do receiver com máquina de estados (sincronizacao 4.4):
 *
 *   claim() → CLAIMED    processa → markProcessed() → 'processed'
 *           → DUPLICATE  evento já 'processed' → 200 sem reprocessar
 *           → IN_FLIGHT  outro worker processando (recente) → 425 (o sender
 *                        do FullFlow classifica 425 como retry com backoff)
 *
 * O evento só vira 'processed' DEPOIS do side effect concluir. Crash entre
 * claim e processamento deixa o registro em 'processing' — a re-entrega
 * recebe 425 enquanto recente, e RECLAIMA quando stale (processing_started_at
 * além de idempotency_stale_minutes), sem perder o evento.
 *
 * Camadas: received_webhook_events (durável, autoridade) + cache (fast-path
 * de DUPLICATE e fallback completo na janela pré-migrate, onde o TTL curto
 * da chave de claim faz o papel do reclaim por staleness).
 */
class IdempotencyChecker
{
    public const CLAIMED = 'claimed';
    public const DUPLICATE = 'duplicate';
    public const IN_FLIGHT = 'in_flight';

    protected static ?bool $tableAvailable = null;

    public function __construct(
        public int $ttlHours = 24,
        public int $staleMinutes = 10,
    ) {}

    /** @return self::CLAIMED|self::DUPLICATE|self::IN_FLIGHT */
    public function claim(string $eventId, string $eventType = ''): string
    {
        if (Cache::has($this->doneKey($eventId))) {
            return self::DUPLICATE;
        }

        if (! $this->tableAvailable()) {
            // Fallback pré-migrate: claim com TTL curto (= janela de stale);
            // se o processo morrer sem markProcessed, a chave expira e a
            // re-entrega seguinte reclaima.
            return Cache::add($this->claimKey($eventId), true, now()->addMinutes($this->staleMinutes))
                ? self::CLAIMED
                : self::IN_FLIGHT;
        }

        $inserted = DB::table('received_webhook_events')->insertOrIgnore([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'status' => 'processing',
            'processing_started_at' => now(),
            'received_at' => now(),
        ]);

        if ($inserted > 0) {
            return self::CLAIMED;
        }

        $row = DB::table('received_webhook_events')->where('event_id', $eventId)->first();
        if ($row === null) {
            // Release concorrente entre o insert e o select — tenta de novo.
            return $this->claim($eventId, $eventType);
        }

        if ($row->status === 'processed') {
            return self::DUPLICATE;
        }

        // 'processing': recente → outro worker está nele; stale → o dono
        // morreu antes de concluir — reclaim condicional (só um ganha).
        $cutoff = now()->subMinutes($this->staleMinutes);
        $reclaimed = DB::table('received_webhook_events')
            ->where('event_id', $eventId)
            ->where('status', 'processing')
            ->where('processing_started_at', '<', $cutoff)
            ->update(['processing_started_at' => now()]);

        return $reclaimed > 0 ? self::CLAIMED : self::IN_FLIGHT;
    }

    /** Side effect concluído: agora (e só agora) o evento é 'processed'. */
    public function markProcessed(string $eventId, string $eventType = ''): void
    {
        Cache::put($this->doneKey($eventId), true, now()->addHours($this->ttlHours));
        Cache::forget($this->claimKey($eventId));

        if ($this->tableAvailable()) {
            DB::table('received_webhook_events')->updateOrInsert(
                ['event_id' => $eventId],
                ['event_type' => $eventType, 'status' => 'processed', 'received_at' => now()]
            );
        }
    }

    /** Falha CONHECIDA de processamento: reabre imediatamente (sem esperar stale). */
    public function release(string $eventId): void
    {
        Cache::forget($this->claimKey($eventId));
        Cache::forget($this->doneKey($eventId));

        if ($this->tableAvailable()) {
            DB::table('received_webhook_events')->where('event_id', $eventId)->delete();
        }
    }

    public function wasProcessed(string $eventId): bool
    {
        if (Cache::has($this->doneKey($eventId))) {
            return true;
        }

        return $this->tableAvailable()
            && DB::table('received_webhook_events')
                ->where('event_id', $eventId)
                ->where('status', 'processed')
                ->exists();
    }

    protected function tableAvailable(): bool
    {
        return static::$tableAvailable ??= Schema::hasTable('received_webhook_events');
    }

    /** @internal somente para testes. */
    public static function resetTableAvailabilityCache(): void
    {
        static::$tableAvailable = null;
    }

    private function doneKey(string $eventId): string
    {
        return "fullflow:webhook_event:{$eventId}";
    }

    private function claimKey(string $eventId): string
    {
        return "fullflow:webhook_claim:{$eventId}";
    }
}
