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
