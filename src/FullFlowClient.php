<?php

namespace Kicol\FullFlow;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kicol\FullFlow\Exceptions\ApiKeyException;
use Kicol\FullFlow\Exceptions\FullFlowException;
use Kicol\FullFlow\Exceptions\InvalidPayloadException;
use Kicol\FullFlow\Exceptions\InvalidTransitionException;
use Kicol\FullFlow\Exceptions\SubscriptionAlreadyExistsException;
use Kicol\FullFlow\Exceptions\SubscriptionNotFoundException;

class FullFlowClient
{
    public function __construct(
        public readonly string $baseUrl,
        protected readonly string $apiKey,
        protected readonly int $timeout = 15,
    ) {}

    /**
     * Cria nova assinatura.
     *
     * @param array $data Payload conforme guia (referencia_externa, cliente, assinatura, fiscal)
     * @return array {assinatura_id, status, trial_ate, primeira_cobranca_em}
     */
    public function createSubscription(array $data): array
    {
        return $this->call('post', '/assinaturas', $data);
    }

    public function getSubscription(string $uuid): array
    {
        return $this->call('get', "/assinaturas/{$uuid}");
    }

    public function cancelSubscription(string $uuid, ?string $motivo = null): array
    {
        return $this->call('post', "/assinaturas/{$uuid}/cancelar", array_filter([
            'motivo' => $motivo,
        ]));
    }

    public function reactivateSubscription(string $uuid, ?string $motivo = null): array
    {
        return $this->call('post', "/assinaturas/{$uuid}/reativar", array_filter([
            'motivo' => $motivo,
        ]));
    }

    /**
     * Lista assinaturas de um cliente (apenas do produto autenticado).
     */
    public function listClientSubscriptions(string $documento): array
    {
        $documento = preg_replace('/\D/', '', $documento);
        return $this->call('get', "/clientes/{$documento}/assinaturas");
    }

    /**
     * Declara/atualiza módulos do produto no FullFlow (idempotente).
     *
     * @param array $modules Lista de [slug, label, tipo, descricao?, visivel_ao_cliente?]
     * @return array {criados[], atualizados[], arquivados[], total}
     */
    public function syncModules(array $modules): array
    {
        return $this->call('post', '/modulos/sync', ['modulos' => $modules]);
    }

    /**
     * Lista planos visíveis ao cliente do produto autenticado.
     * Retorna o array bruto da API; use FullFlow::pullCatalog() para também
     * persistir nas tabelas locais.
     */
    public function listPlans(): array
    {
        return $this->call('get', '/planos');
    }

    /**
     * Sincroniza planos+módulos no banco LOCAL (tabelas fullflow_plans,
     * fullflow_modules, fullflow_plan_modules). Idempotente.
     */
    public function pullCatalog(): array
    {
        $payload = $this->listPlans();
        $planos = $payload['planos'] ?? [];

        $now = now();
        $modulesByCode = [];
        $modulesById = [];

        \Illuminate\Support\Facades\DB::transaction(function () use ($planos, $now, &$modulesByCode, &$modulesById) {
            // 1) Coletar todos os módulos únicos por slug
            $allModules = collect($planos)
                ->flatMap(fn ($p) => $p['modulos'] ?? [])
                ->unique('slug')
                ->values();

            foreach ($allModules as $m) {
                $module = \Kicol\FullFlow\Models\FullFlowModule::updateOrCreate(
                    ['slug' => $m['slug']],
                    [
                        'label' => $m['label'],
                        'description' => $m['description'] ?? null,
                        'type' => $m['tipo'],
                        'visible_to_client' => true,
                        'synced_at' => $now,
                    ]
                );
                $modulesByCode[$m['slug']] = $module->id;
            }

            // 2) Upsert dos planos e sincronizar pivots
            $planosKept = [];
            foreach ($planos as $p) {
                $plan = \Kicol\FullFlow\Models\FullFlowPlan::updateOrCreate(
                    ['code' => $p['code']],
                    [
                        'name' => $p['name'],
                        'description' => $p['description'] ?? null,
                        'billing_cycle' => $p['billing_cycle'],
                        'amount' => $p['amount'],
                        'trial_days' => $p['trial_days'] ?? 0,
                        'sort_order' => $p['sort_order'] ?? 0,
                        'synced_at' => $now,
                    ]
                );
                $planosKept[] = $plan->code;

                $sync = [];
                foreach ($p['modulos'] ?? [] as $m) {
                    if (! isset($modulesByCode[$m['slug']])) {
                        continue;
                    }
                    $sync[$modulesByCode[$m['slug']]] = ['quota_value' => $m['quota'] ?? null];
                }
                $plan->modules()->sync($sync);
            }

            // 3) Remover planos que não vieram (sumiram do FullFlow)
            \Kicol\FullFlow\Models\FullFlowPlan::query()
                ->whereNotIn('code', $planosKept)
                ->delete();
        });

        return [
            'planos' => count($planos),
            'modulos' => count($modulesByCode),
            'synced_at' => $now->toIso8601String(),
        ];
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
            'Accept' => 'application/json',
        ])->timeout($this->timeout)->baseUrl($this->baseUrl);
    }

    protected function call(string $method, string $path, array $data = []): array
    {
        $response = $this->http()->{$method}($path, $data);
        return $this->handle($response);
    }

    protected function handle(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?: [];
        }

        $body = $response->json() ?: [];
        $codigo = $body['codigo'] ?? null;
        $mensagem = $body['mensagem'] ?? $response->body();

        match (true) {
            $response->status() === 401 => throw new ApiKeyException($mensagem),
            $response->status() === 404 => throw new SubscriptionNotFoundException($mensagem),
            $response->status() === 409 && $codigo === 'referencia_externa_duplicada'
                => throw new SubscriptionAlreadyExistsException($mensagem),
            $response->status() === 409 && $codigo === 'transicao_invalida'
                => throw new InvalidTransitionException($mensagem),
            $response->status() === 400 => throw new InvalidPayloadException($mensagem, $body['detalhes'] ?? []),
            default => throw new FullFlowException("FullFlow API error ({$response->status()}): {$mensagem}"),
        };
    }
}
