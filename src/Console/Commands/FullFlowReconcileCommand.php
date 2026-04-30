<?php

namespace Kicol\FullFlow\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kicol\FullFlow\Exceptions\SubscriptionNotFoundException;
use Kicol\FullFlow\FullFlowClient;
use Kicol\FullFlow\Models\FullFlowSubscription;

class FullFlowReconcileCommand extends Command
{
    protected $signature = 'fullflow:reconcile {--limit= : Quantas assinaturas processar nesta execução}';

    protected $description = 'Reconcilia status de assinaturas locais com o FullFlow.';

    public function handle(FullFlowClient $client): int
    {
        $limit = (int) ($this->option('limit') ?: config('fullflow.reconcile_batch_size', 100));
        $modelClass = config('fullflow.subscription_model', FullFlowSubscription::class);

        $batch = $modelClass::query()
            ->whereNotIn('status', ['cancelada'])
            ->orderBy('last_synced_at', 'asc')
            ->limit($limit)
            ->get();

        if ($batch->isEmpty()) {
            $this->info('Nada para reconciliar.');
            return self::SUCCESS;
        }

        $synced = 0;
        $drifts = 0;
        $errors = 0;

        foreach ($batch as $sub) {
            try {
                $remote = $client->getSubscription($sub->fullflow_id);

                if ($sub->status !== $remote['status']) {
                    Log::warning("FullFlow drift detected: local={$sub->status} remote={$remote['status']} sub={$sub->fullflow_id}");
                    $drifts++;
                }

                $sub->update([
                    'status' => $remote['status'],
                    'trial_until' => $remote['trial_ate'] ?? null,
                    'current_period_start' => $remote['inicio_periodo_atual'] ?? null,
                    'current_period_end' => $remote['fim_periodo_atual'] ?? null,
                    'amount' => $remote['valor'] ?? $sub->amount,
                    'last_synced_at' => now(),
                ]);

                $synced++;
            } catch (SubscriptionNotFoundException) {
                Log::warning("FullFlow: assinatura {$sub->fullflow_id} não existe mais remotamente.");
                $errors++;
            } catch (\Throwable $e) {
                Log::error("FullFlow reconcile error sub={$sub->fullflow_id}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info("Reconciliados: {$synced} | Drifts: {$drifts} | Erros: {$errors}");
        return self::SUCCESS;
    }
}
