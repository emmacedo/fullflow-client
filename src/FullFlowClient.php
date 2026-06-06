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

    /**
     * Busca assinatura pela referência externa (do produto autenticado).
     * Lança SubscriptionNotFoundException se não existir.
     *
     * O endpoint responde no formato do SubscriptionResource ('id'), mas o
     * create responde 'assinatura_id' — o onboarding reaproveita
     * 'assinatura_id' após um 409, então normalizamos aqui para o consumidor
     * ler o MESMO nome nos dois caminhos.
     *
     * @param string $reference Ex: 'kicol_customer_13' ou 'kicol_store_5'
     */
    public function getSubscriptionByReference(string $reference): array
    {
        $data = $this->call('get', '/assinaturas', ['referencia_externa' => $reference]);

        if (! isset($data['assinatura_id']) && isset($data['id'])) {
            $data['assinatura_id'] = $data['id'];
        }

        return $data;
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
     * Upgrade — vigora imediato, gera cobrança avulsa proporcional.
     */
    public function upgradeSubscription(string $uuid, string $planCode, ?string $motivo = null): array
    {
        return $this->call('post', "/assinaturas/{$uuid}/upgrade", array_filter([
            'plan_code' => $planCode,
            'motivo' => $motivo,
        ]));
    }

    /**
     * Downgrade — vigora no próximo ciclo (Trial troca imediato), gera credit.
     */
    public function downgradeSubscription(string $uuid, string $planCode, ?string $motivo = null): array
    {
        return $this->call('post', "/assinaturas/{$uuid}/downgrade", array_filter([
            'plan_code' => $planCode,
            'motivo' => $motivo,
        ]));
    }

    /**
     * Lista cobranças de uma assinatura.
     *
     * @param string $status 'em_aberto' (default), 'pagas' ou 'todas'
     */
    public function listCharges(string $uuid, string $status = 'em_aberto'): array
    {
        return $this->call('get', "/assinaturas/{$uuid}/cobrancas", ['status' => $status]);
    }

    /**
     * Lista cobranças de todas as assinaturas de um cliente (no produto autenticado).
     */
    public function listClientCharges(string $documento, string $status = 'em_aberto'): array
    {
        $documento = preg_replace('/\D/', '', $documento);
        return $this->call('get', "/clientes/{$documento}/cobrancas", ['status' => $status]);
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
     * Push do catálogo declarativo completo no contrato 3.3 (EN):
     * {modules[], features[], module_features[]}. Usado pelo catalog-sync
     * v0.8 quando o catálogo vive nas tabelas locais (CL-8).
     *
     * @return array {criados[], atualizados[], arquivados[], total}
     */
    public function syncCatalog(array $catalog): array
    {
        return $this->call('post', '/modulos/sync', $catalog);
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
     * Lista pacotes excedentes (add-ons) disponíveis para um plano.
     */
    public function listAddons(string $planCode): array
    {
        return $this->call('get', '/addons', ['plan_code' => $planCode]);
    }

    /**
     * Inicia compra de add-on.
     *
     * @param string $paymentMethod Hoje só 'pix' (default); valor inválido → 422.
     * @return array {purchase_id, addon_code, quantity, total_amount, credits_total, status, payment_method, pix}
     */
    public function purchaseAddon(string $subscriptionCode, string $addonCode, int $quantity = 1, string $paymentMethod = 'pix'): array
    {
        return $this->call('post', '/addons/comprar', [
            'subscription_code' => $subscriptionCode,
            'addon_code' => $addonCode,
            'quantity' => $quantity,
            'payment_method' => $paymentMethod,
        ]);
    }

    /**
     * Consulta status de uma compra de add-on.
     */
    public function getAddonPurchase(string $purchaseId): array
    {
        return $this->call('get', "/addons/compra/{$purchaseId}");
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
                        'is_custom_pricing' => $p['is_custom_pricing'] ?? false,
                        'visible_to_client' => $p['visible_to_client'] ?? true,
                        'trial_days' => $p['trial_days'] ?? 0,
                        'sort_order' => $p['sort_order'] ?? 0,
                        'plan_version' => $p['plan_version'] ?? 0,
                        'active' => true,
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

                // Espelho de features (v0.8): o GET retorna superset
                // (key/label/unit/period/quota) — o parser usa SÓ key+quota,
                // mesmo contrato do webhook plan.updated (nota da Etapa 3).
                $this->syncPlanFeaturesMirror($plan, $p['features'] ?? []);
            }

            // 3) Planos que não vieram sumiram do GET (active=true no
            // FullFlow) → marca inativo em vez de deletar (reconcile 4.10):
            // preserva histórico para assinaturas antigas.
            \Kicol\FullFlow\Models\FullFlowPlan::query()
                ->whereNotIn('code', $planosKept)
                ->update(['active' => false, 'synced_at' => $now]);
        });

        return [
            'planos' => count($planos),
            'modulos' => count($modulesByCode),
            'synced_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Sincroniza o espelho fullflow_plan_features (plan_id + feature_id →
     * features.key — DDL do catálogo 3.2) a partir da lista de features do
     * GET/webhook. Ignora campos extras (label/unit/period); key desconhecida
     * no catálogo local é pulada com warning (features é autoridade do dev).
     * No-op quando as tabelas ainda não existem (janela pré-F3).
     */
    protected function syncPlanFeaturesMirror(\Kicol\FullFlow\Models\FullFlowPlan $plan, array $features): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('fullflow_plan_features')
            || ! \Illuminate\Support\Facades\Schema::hasTable('features')) {
            return;
        }

        $rows = [];
        foreach ($features as $f) {
            $key = (string) ($f['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $featureId = \Illuminate\Support\Facades\DB::table('features')->where('key', $key)->value('id');
            if ($featureId === null) {
                \Illuminate\Support\Facades\Log::warning('FullFlow pullCatalog: feature_key desconhecida no catálogo local — pivot pulado.', [
                    'plan_code' => $plan->code,
                    'feature_key' => $key,
                ]);

                continue;
            }

            $rows[] = ['plan_id' => $plan->id, 'feature_id' => $featureId, 'quota' => $f['quota'] ?? null];
        }

        \Illuminate\Support\Facades\DB::table('fullflow_plan_features')->where('plan_id', $plan->id)->delete();
        if ($rows !== []) {
            \Illuminate\Support\Facades\DB::table('fullflow_plan_features')->insert($rows);
        }
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
        // 'mensagem' = envelope de erro do FullFlow; 'message' = formato padrão
        // do Laravel (422 de FormRequest vem assim).
        $mensagem = $body['mensagem'] ?? $body['message'] ?? $response->body();

        match (true) {
            $response->status() === 401 => throw new ApiKeyException($mensagem),
            $response->status() === 404 => throw new SubscriptionNotFoundException($mensagem),
            $response->status() === 409 && $codigo === 'referencia_externa_duplicada'
                => throw new SubscriptionAlreadyExistsException($mensagem),
            $response->status() === 409 && $codigo === 'transicao_invalida'
                => throw new InvalidTransitionException($mensagem),
            $response->status() === 400 => throw new InvalidPayloadException($mensagem, $body['detalhes'] ?? []),
            // 422 = validação Laravel (FormRequest): errors é dict campo→mensagens.
            $response->status() === 422 => throw new InvalidPayloadException($mensagem, $body['errors'] ?? $body['detalhes'] ?? []),
            default => throw new FullFlowException("FullFlow API error ({$response->status()}): {$mensagem}"),
        };
    }
}
