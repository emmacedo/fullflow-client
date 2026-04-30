<?php

namespace Kicol\FullFlow;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
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

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'fullflow-migrations');

        // Migrations NÃO são auto-carregadas — cada SaaS publica via:
        //   php artisan vendor:publish --tag=fullflow-migrations
        // ou cria sua própria migration adaptada ao seu modelo de tenant
        // (ex: store_owner_id no eBookView).

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('fullflow.subscription.active', EnsureSubscriptionActive::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                FullFlowReconcileCommand::class,
            ]);
        }
    }
}
