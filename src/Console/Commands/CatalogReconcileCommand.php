<?php

namespace Kicol\FullFlow\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kicol\FullFlow\FullFlowClient;
use Kicol\FullFlow\Models\FullFlowPlan;
use Kicol\FullFlow\Webhook\Handlers\PlanUpdatedHandler;

/**
 * Reconciliação semanal do espelho de planos (sincronizacao-real-time 4.10):
 * rede de segurança do webhook plan.updated.
 *
 *  1. GET /planos no FullFlow.
 *  2. plan_version local ≠ incoming → aplica via PlanUpdatedHandler (mesmo
 *     código do webhook, sem o gate de event_id) — inclusive disparando
 *     FullFlowPlanUpdated para o SaaS invalidar caches.
 *  3. Planos locais ativos que não voltaram do GET → active=false.
 *  4. Drift consistente = webhook com problema sistêmico (alerta nos logs).
 */
class CatalogReconcileCommand extends Command
{
    protected $signature = 'fullflow:catalog-reconcile';

    protected $description = 'Reconcilia o espelho local de planos com o FullFlow (rede de segurança do webhook plan.updated).';

    public function handle(FullFlowClient $client, PlanUpdatedHandler $handler): int
    {
        try {
            $remotePlans = $client->listPlans()['planos'] ?? [];
        } catch (\Throwable $e) {
            $this->error('Falha ao consultar o FullFlow: ' . $e->getMessage());

            return self::FAILURE;
        }

        $drifts = 0;
        $applied = 0;
        $remoteCodes = [];

        foreach ($remotePlans as $plano) {
            $code = (string) ($plano['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $remoteCodes[] = $code;

            $incomingVersion = (int) ($plano['plan_version'] ?? 0);
            $local = FullFlowPlan::query()->where('code', $code)->first();

            if ($local && (int) $local->plan_version === $incomingVersion) {
                continue;
            }

            $drifts++;
            Log::warning('FullFlow catalog-reconcile: drift detectado.', [
                'code' => $code,
                'local_version' => $local?->plan_version,
                'incoming_version' => $incomingVersion,
            ]);

            if ($handler->handle($this->syntheticPayload($plano))) {
                $applied++;
            }
        }

        // Planos locais ativos que sumiram do GET (active=true no FullFlow é
        // o filtro do endpoint) → desativados lá → marca inativo aqui.
        $deactivated = FullFlowPlan::query()
            ->where('active', true)
            ->whereNotIn('code', $remoteCodes)
            ->get();

        foreach ($deactivated as $plan) {
            $plan->update(['active' => false, 'synced_at' => now()]);
            Log::warning('FullFlow catalog-reconcile: plano ausente do FullFlow marcado inativo.', [
                'code' => $plan->code,
            ]);
        }

        $this->info(sprintf(
            'Planos remotos: %d | Drifts: %d | Aplicados: %d | Desativados: %d',
            count($remotePlans),
            $drifts,
            $applied,
            $deactivated->count()
        ));

        if ($drifts > 0) {
            $this->warn('Drift encontrado — se recorrente, o webhook plan.updated está com problema sistêmico.');
        }

        return self::SUCCESS;
    }

    /**
     * Adapta um plano do GET /planos (superset, formato do PlanResource) para
     * o envelope do plan.updated que o handler consome — usa só o que o
     * contrato do webhook carrega (modules key, features key+quota).
     */
    private function syntheticPayload(array $plano): array
    {
        return [
            'event_type' => 'plan.updated',
            'data' => [
                'plan_version' => (int) ($plano['plan_version'] ?? 0),
                'plan' => [
                    'code' => $plano['code'],
                    'name' => $plano['name'] ?? $plano['code'],
                    'description' => $plano['description'] ?? null,
                    'billing_cycle' => $plano['billing_cycle'] ?? 'mensal',
                    'amount' => $plano['amount'] ?? 0,
                    'is_custom_pricing' => $plano['is_custom_pricing'] ?? false,
                    'visible_to_client' => $plano['visible_to_client'] ?? true,
                    'trial_days' => $plano['trial_days'] ?? 0,
                    'sort_order' => $plano['sort_order'] ?? 0,
                    'active' => true, // GET filtra active=true
                ],
                'modules' => collect($plano['modulos'] ?? [])
                    ->map(fn ($m) => ['key' => $m['slug'] ?? $m['key'] ?? '', 'quota_value' => $m['quota'] ?? null])
                    ->filter(fn ($m) => $m['key'] !== '')
                    ->values()
                    ->all(),
                'features' => collect($plano['features'] ?? [])
                    ->map(fn ($f) => ['key' => $f['key'] ?? '', 'quota' => $f['quota'] ?? null])
                    ->filter(fn ($f) => $f['key'] !== '')
                    ->values()
                    ->all(),
            ],
        ];
    }
}
