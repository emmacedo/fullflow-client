<?php

namespace Kicol\FullFlow;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Kicol\FullFlow\Console\Commands\FullFlowCatalogSyncCommand;
use Kicol\FullFlow\Console\Commands\FullFlowReconcileCommand;
use Kicol\FullFlow\Middleware\EnsureSubscriptionActive;
use Kicol\FullFlow\Webhook\IdempotencyChecker;
use Kicol\FullFlow\Webhook\SignatureValidator;

class FullFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/fullflow.php', 'fullflow');

        $this->app->singleton(FullFlowClient::class, function ($app) {
            return new FullFlowClient(
                baseUrl: rtrim(config('fullflow.base_url', ''), '/'),
                apiKey: config('fullflow.api_key', ''),
                timeout: config('fullflow.timeout', 15),
            );
        });

        $this->app->bind(SignatureValidator::class);
        $this->app->bind(IdempotencyChecker::class, function () {
            return new IdempotencyChecker((int) config('fullflow.idempotency_ttl_hours', 24));
        });

        $this->app->alias(FullFlowClient::class, 'fullflow.client');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/fullflow.php' => config_path('fullflow.php'),
        ], 'fullflow-config');

        // Migration de fullflow_subscriptions é PUBLICÁVEL pois cada SaaS
        // adapta a FK de tenant (user_id, store_owner_id, etc.).
        $this->publishes([
            __DIR__ . '/../database/stubs/migrations' => database_path('migrations'),
        ], 'fullflow-migrations');

        // Migrations do CATÁLOGO (modules, plans, plan_modules) são auto-loaded —
        // não variam entre SaaS, então rodam direto com `php artisan migrate`.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('fullflow.subscription.active', EnsureSubscriptionActive::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                FullFlowReconcileCommand::class,
                FullFlowCatalogSyncCommand::class,
            ]);

            // Schedule do catalog-sync — opt-in via config (default 03:00).
            // Para desligar, defina FULLFLOW_CATALOG_SYNC_AT=null no .env.
            $this->app->booted(function () {
                $cron = config('fullflow.catalog_sync_at');
                if (! $cron) {
                    return;
                }
                /** @var Schedule $schedule */
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('fullflow:catalog-sync')
                    ->dailyAt($cron)
                    ->withoutOverlapping()
                    ->name('fullflow-catalog-sync');
            });
        }
    }
}
