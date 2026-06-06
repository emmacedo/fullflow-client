<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência durável do receiver de webhooks (sincronizacao-real-time 4.4,
 * camada 1): event_id já visto → 200 sem reprocessar. Substitui o cache TTL
 * 24h como autoridade (cache vira fast-path). Limpeza após 30 dias por cron.
 *
 * Auto-load do pacote — infra do receiver, igual em todos os SaaS. A fase F2
 * do plano de migração do KicolApps NÃO deve recriar esta tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('received_webhook_events', function (Blueprint $table) {
            // 64: o produtor real (SubscriptionWebhookOutbox) emite UUID de 36
            // chars — o CHAR(26) do desenho original assumia ULID. 64 cobre
            // UUID, ULID e formatos futuros.
            $table->string('event_id', 64)->primary();
            $table->string('event_type', 64);
            // Máquina de estados do claim: 'processing' (em voo — duplicata
            // recente leva 425; stale pode ser reclamado) → 'processed' (só
            // após o side effect concluir). Evita que um crash entre claim e
            // processamento transforme a re-entrega em "already processed".
            $table->string('status', 16)->default('processing');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('received_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('received_webhook_events');
    }
};
