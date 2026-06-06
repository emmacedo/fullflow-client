<?php

namespace Kicol\FullFlow\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kicol\FullFlow\FullFlowClient;

class FullFlowCatalogSyncCommand extends Command
{
    protected $signature = 'fullflow:catalog-sync
                            {--skip-push : Pula a declaração de módulos (apenas pull)}
                            {--skip-pull : Pula o pull dos planos (apenas push)}';

    protected $description = 'Declara módulos do app e sincroniza catálogo de planos do FullFlow para o banco local';

    public function handle(FullFlowClient $client): int
    {
        if (! $this->option('skip-push')) {
            $failure = $this->push($client);
            if ($failure !== null) {
                return $failure;
            }
        }

        if (! $this->option('skip-pull')) {
            $this->info('Puxando catálogo de planos...');
            try {
                $result = $client->pullCatalog();
                $this->line(sprintf(
                    '  %d plano(s), %d módulo(s) sincronizados em %s',
                    $result['planos'],
                    $result['modulos'],
                    $result['synced_at']
                ));
            } catch (\Throwable $e) {
                $this->error('Falha no pull do catálogo: '.$e->getMessage());
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @return int|null exit code de falha, ou null para continuar. */
    private function push(FullFlowClient $client): ?int
    {
        // CL-8: o catálogo declarativo passa a viver nas tabelas locais
        // (modules/features/module_features, fase F3). Se existem e estão
        // populadas, são a fonte do push (contrato 3.3 EN completo);
        // senão, fallback ao config legado (outros SaaS / janela pré-F3).
        $catalog = $this->localCatalogPayload();

        if ($catalog !== null) {
            $this->info(sprintf(
                'Declarando catálogo local no FullFlow (%d módulo(s), %d feature(s), %d vínculo(s))...',
                count($catalog['modules']),
                count($catalog['features']),
                count($catalog['module_features'])
            ));

            try {
                $this->printPushResult($client->syncCatalog($catalog));
            } catch (\Throwable $e) {
                $this->error('Falha no push do catálogo: '.$e->getMessage());
                return self::FAILURE;
            }

            return null;
        }

        $modulesConfig = config('fullflow.modules', []);
        if (empty($modulesConfig)) {
            $this->warn('Nenhum módulo declarado (tabelas locais vazias e config/fullflow.php sem módulos) — pulando push.');
            return null;
        }

        $this->info('Declarando '.count($modulesConfig).' módulo(s) do config no FullFlow...');
        try {
            $this->printPushResult($client->syncModules($modulesConfig));
        } catch (\Throwable $e) {
            $this->error('Falha no push de módulos: '.$e->getMessage());
            return self::FAILURE;
        }

        return null;
    }

    /**
     * Monta o payload 3.3 (EN) a partir das tabelas locais do catálogo
     * declarativo. NULL quando as tabelas não existem ou modules está vazia
     * (fallback ao config legado). Mapeia name→label (DDL local usa name).
     */
    private function localCatalogPayload(): ?array
    {
        foreach (['modules', 'features', 'module_features'] as $table) {
            if (! Schema::hasTable($table)) {
                return null;
            }
        }

        $modules = DB::table('modules')->orderBy('key')->get();
        if ($modules->isEmpty()) {
            return null;
        }

        return [
            'modules' => $modules->map(fn ($m) => array_filter([
                'key' => $m->key,
                'label' => $m->name,
                'description' => $m->description,
                'availability' => $m->availability,
            ], fn ($v) => $v !== null))->values()->all(),
            'features' => DB::table('features')->orderBy('key')->get()->map(fn ($f) => array_filter([
                'key' => $f->key,
                'label' => $f->name,
                'description' => $f->description ?? null,
                'unit' => $f->unit,
                'period' => $f->period,
            ], fn ($v) => $v !== null))->values()->all(),
            'module_features' => DB::table('module_features as mf')
                ->join('modules as m', 'm.id', '=', 'mf.module_id')
                ->join('features as f', 'f.id', '=', 'mf.feature_id')
                ->orderBy('m.key')->orderBy('f.key')
                ->get(['m.key as module_key', 'f.key as feature_key'])
                ->map(fn ($v) => ['module_key' => $v->module_key, 'feature_key' => $v->feature_key])
                ->all(),
        ];
    }

    private function printPushResult(array $result): void
    {
        $this->line(sprintf(
            '  criados: %d / atualizados: %d / arquivados: %d / total: %d',
            count($result['criados'] ?? []),
            count($result['atualizados'] ?? []),
            count($result['arquivados'] ?? []),
            $result['total'] ?? 0
        ));
    }
}
