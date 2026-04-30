<?php

namespace Kicol\FullFlow\Console\Commands;

use Illuminate\Console\Command;
use Kicol\FullFlow\FullFlowClient;

class FullFlowCatalogSyncCommand extends Command
{
    protected $signature = 'fullflow:catalog-sync
                            {--skip-push : Pula a declaração de módulos (apenas pull)}
                            {--skip-pull : Pula o pull dos planos (apenas push)}';

    protected $description = 'Declara módulos do app e sincroniza catálogo de planos do FullFlow para o banco local';

    public function handle(FullFlowClient $client): int
    {
        $modulesConfig = config('fullflow.modules', []);

        if (! $this->option('skip-push')) {
            if (empty($modulesConfig)) {
                $this->warn('Nenhum módulo declarado em config/fullflow.php — pulando push.');
            } else {
                $this->info('Declarando '.count($modulesConfig).' módulo(s) no FullFlow...');
                try {
                    $result = $client->syncModules($modulesConfig);
                    $this->line(sprintf(
                        '  criados: %d / atualizados: %d / arquivados: %d / total: %d',
                        count($result['criados'] ?? []),
                        count($result['atualizados'] ?? []),
                        count($result['arquivados'] ?? []),
                        $result['total'] ?? 0
                    ));
                } catch (\Throwable $e) {
                    $this->error('Falha no push de módulos: '.$e->getMessage());
                    return self::FAILURE;
                }
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
}
