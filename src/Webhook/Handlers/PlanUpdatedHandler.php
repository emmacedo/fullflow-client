<?php

namespace Kicol\FullFlow\Webhook\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Kicol\FullFlow\Events\FullFlowPlanUpdated;
use Kicol\FullFlow\Models\FullFlowModule;
use Kicol\FullFlow\Models\FullFlowPlan;
use Kicol\FullFlow\Webhook\WebhookPayload;

/**
 * Aplica o evento plan.updated no espelho local (CL-4, sincronizacao 4.4/4.8).
 *
 * Idempotência em 3 camadas:
 *  1. event_id — resolvida ANTES daqui (IdempotencyChecker no controller).
 *  2. plan_version — incoming <= local → descarte silencioso (já temos
 *     versão igual ou mais nova; cobre entrega fora de ordem).
 *  3. Transação atômica — upsert do plano + sync de módulos + sync de
 *     features: tudo ou nada.
 *
 * Snapshot TOTAL de regra: módulo/feature ausente do payload foi removido
 * do plano (sync remove pivots ausentes).
 *
 * Fronteiras do espelho:
 *  - fullflow_modules é ESPELHO — módulo desconhecido vira stub mínimo
 *    (slug=key), completado pelo próximo pull/reconcile.
 *  - features (local) é AUTORIDADE do dev — key desconhecida NÃO vira stub:
 *    pivot é pulado com warning (catálogo local desatualizado; o push da
 *    F3/F9 e o reconcile corrigem).
 */
class PlanUpdatedHandler
{
    /** @return bool true quando o evento foi aplicado OU descartado por versão (ambos finais). */
    public function handle(array $payload): bool
    {
        $data = WebhookPayload::data($payload);
        $incomingVersion = (int) ($data['plan_version'] ?? 0);
        $planData = (array) ($data['plan'] ?? []);
        $code = (string) ($planData['code'] ?? '');

        if ($code === '' || $incomingVersion < 1) {
            Log::warning('FullFlow plan.updated: payload sem plan.code ou plan_version — ignorado.', [
                'event_id' => WebhookPayload::eventId($payload),
            ]);

            return false;
        }

        // Camada 2 — gate de versão (entrega fora de ordem / replay).
        $local = FullFlowPlan::query()->where('code', $code)->first();
        if ($local && (int) $local->plan_version >= $incomingVersion) {
            Log::info('FullFlow plan.updated: versão antiga descartada.', [
                'code' => $code,
                'local_version' => $local->plan_version,
                'incoming_version' => $incomingVersion,
            ]);

            return true;
        }

        // Camada 3 — transação atômica.
        DB::transaction(function () use ($planData, $data, $code, $incomingVersion) {
            $plan = FullFlowPlan::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $planData['name'] ?? $code,
                    'description' => $planData['description'] ?? null,
                    'billing_cycle' => $planData['billing_cycle'] ?? 'mensal',
                    'amount' => $planData['amount'] ?? 0,
                    'is_custom_pricing' => $planData['is_custom_pricing'] ?? false,
                    'visible_to_client' => $planData['visible_to_client'] ?? true,
                    'trial_days' => $planData['trial_days'] ?? 0,
                    'sort_order' => $planData['sort_order'] ?? 0,
                    'active' => $planData['active'] ?? true,
                    'plan_version' => $incomingVersion,
                    'synced_at' => now(),
                ]
            );

            $this->syncModules($plan, (array) ($data['modules'] ?? []));
            $this->syncFeatures($plan, (array) ($data['features'] ?? []));
        });

        event(new FullFlowPlanUpdated($payload));

        return true;
    }

    /** @param array<int, array{key: string}> $modules */
    protected function syncModules(FullFlowPlan $plan, array $modules): void
    {
        $sync = [];
        foreach ($modules as $m) {
            $key = (string) ($m['key'] ?? $m['slug'] ?? '');
            if ($key === '') {
                continue;
            }

            // Espelho: módulo desconhecido vira stub mínimo (o webhook só
            // carrega a key); pull/reconcile completam label/type depois.
            $module = FullFlowModule::firstOrCreate(
                ['slug' => $key],
                ['label' => $key, 'type' => 'boolean', 'synced_at' => now()]
            );

            $sync[$module->id] = ['quota_value' => $m['quota_value'] ?? null];
        }

        $plan->modules()->sync($sync);
    }

    /** @param array<int, array{key: string, quota: int|null}> $features */
    protected function syncFeatures(FullFlowPlan $plan, array $features): void
    {
        if (! static::featureMirrorAvailable()) {
            if ($features !== []) {
                Log::warning('FullFlow plan.updated: tabelas do espelho de features ausentes — features não aplicadas.', [
                    'code' => $plan->code,
                ]);
            }

            return;
        }

        $rows = [];
        foreach ($features as $f) {
            $key = (string) ($f['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $featureId = DB::table('features')->where('key', $key)->value('id');
            if ($featureId === null) {
                // features local é autoridade do dev — não criar stub.
                Log::warning('FullFlow plan.updated: feature_key desconhecida no catálogo local — pivot pulado.', [
                    'code' => $plan->code,
                    'feature_key' => $key,
                ]);

                continue;
            }

            $rows[] = [
                'plan_id' => $plan->id,
                'feature_id' => $featureId,
                'quota' => isset($f['quota']) ? $f['quota'] : null,
            ];
        }

        // Snapshot total: remove o que não veio, upserta o que veio.
        DB::table('fullflow_plan_features')->where('plan_id', $plan->id)->delete();
        if ($rows !== []) {
            DB::table('fullflow_plan_features')->insert($rows);
        }
    }

    protected static ?bool $featureMirrorAvailable = null;

    protected static function featureMirrorAvailable(): bool
    {
        return static::$featureMirrorAvailable
            ??= Schema::hasTable('fullflow_plan_features') && Schema::hasTable('features');
    }

    /** @internal somente para testes. */
    public static function resetFeatureMirrorCache(): void
    {
        static::$featureMirrorAvailable = null;
    }
}
