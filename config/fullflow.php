<?php

return [
    /*
    |--------------------------------------------------------------------------
    | URL base e credenciais
    |--------------------------------------------------------------------------
    */
    'base_url' => env('FULLFLOW_BASE_URL', 'https://fullflow.app.br/api/v1'),
    'api_key' => env('FULLFLOW_API_KEY'),
    'webhook_secret' => env('FULLFLOW_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('FULLFLOW_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    | replay_protection_minutes: rejeita payloads com timestamp fora dessa janela.
    | idempotency_ttl_hours: quanto tempo guardar event_id para dedupe.
    */
    'replay_protection_minutes' => (int) env('FULLFLOW_REPLAY_MINUTES', 5),
    'idempotency_ttl_hours' => (int) env('FULLFLOW_IDEMPOTENCY_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Reconciliação
    |--------------------------------------------------------------------------
    */
    'reconcile_batch_size' => (int) env('FULLFLOW_RECONCILE_BATCH', 100),

    /*
    |--------------------------------------------------------------------------
    | Persistência local
    |--------------------------------------------------------------------------
    | Tabela e model usados pelo middleware EnsureSubscriptionActive e o
    | comando fullflow:reconcile.
    */
    'subscriptions_table' => env('FULLFLOW_SUBSCRIPTIONS_TABLE', 'fullflow_subscriptions'),
    'subscription_model' => env('FULLFLOW_SUBSCRIPTION_MODEL', \Kicol\FullFlow\Models\FullFlowSubscription::class),

    /*
    |--------------------------------------------------------------------------
    | Status que liberam acesso
    |--------------------------------------------------------------------------
    */
    'access_allowed_statuses' => [
        'trial',
        'ativa',
        'past_due',
        'cancelamento_agendado',
    ],

    /*
    |--------------------------------------------------------------------------
    | Catálogo de Módulos do App
    |--------------------------------------------------------------------------
    | Lista de módulos (features) que este SaaS oferece. Enviada ao FullFlow
    | pelo comando `fullflow:catalog-sync`. Operador atribui aos planos no
    | painel do FullFlow.
    |
    | Cada módulo:
    |   slug                — identificador snake_case usado no código
    |   label               — nome amigável (visível ao cliente)
    |   tipo                — 'boolean' (tem ou não tem) ou 'quantity' (com quota)
    |   descricao           — opcional
    |   visivel_ao_cliente  — opcional (default true), false = só admin
    */
    'modules' => [],

    /*
    |--------------------------------------------------------------------------
    | Schedule do sync diário do catálogo
    |--------------------------------------------------------------------------
    | Horário no formato HH:MM em que o comando fullflow:catalog-sync roda
    | automaticamente. Defina null/vazio para desabilitar.
    */
    'catalog_sync_at' => env('FULLFLOW_CATALOG_SYNC_AT', '03:00'),
];
