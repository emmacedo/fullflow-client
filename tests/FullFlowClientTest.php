<?php

namespace Kicol\FullFlow\Tests;

use Illuminate\Support\Facades\Http;
use Kicol\FullFlow\Exceptions\ApiKeyException;
use Kicol\FullFlow\Exceptions\FullFlowException;
use Kicol\FullFlow\Exceptions\InvalidPayloadException;
use Kicol\FullFlow\Exceptions\SubscriptionNotFoundException;
use Kicol\FullFlow\FullFlowClient;
use Orchestra\Testbench\TestCase;

/**
 * Contratos HTTP do client v0.8 (Etapa 8 da migração — CL-1, CL-2, CL-3):
 * mapeamento de 422, getSubscriptionByReference e payment_method no
 * purchaseAddon (com retrocompat do call site de 3 argumentos).
 */
class FullFlowClientTest extends TestCase
{
    private function client(): FullFlowClient
    {
        return new FullFlowClient('https://fullflow.test/api/v1', 'test-key');
    }

    // ---- CL-1: 422 → InvalidPayloadException ----

    public function test_422_maps_to_invalid_payload_exception_with_laravel_errors(): void
    {
        Http::fake([
            '*' => Http::response([
                'message' => 'O campo quantity deve ser no mínimo 1.',
                'errors' => ['quantity' => ['O campo quantity deve ser no mínimo 1.']],
            ], 422),
        ]);

        try {
            $this->client()->purchaseAddon('sub_x', 'pacote_5k', 0);
            $this->fail('Esperava InvalidPayloadException.');
        } catch (InvalidPayloadException $e) {
            $this->assertSame('O campo quantity deve ser no mínimo 1.', $e->getMessage());
            $this->assertSame(['quantity' => ['O campo quantity deve ser no mínimo 1.']], $e->errors);
        }
    }

    public function test_400_still_maps_to_invalid_payload_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'codigo' => 'payload_invalido',
                'mensagem' => 'Payload inválido.',
                'detalhes' => ['cliente.cpf_cnpj' => 'obrigatório'],
            ], 400),
        ]);

        try {
            $this->client()->createSubscription([]);
            $this->fail('Esperava InvalidPayloadException.');
        } catch (InvalidPayloadException $e) {
            $this->assertSame('Payload inválido.', $e->getMessage());
            $this->assertSame(['cliente.cpf_cnpj' => 'obrigatório'], $e->errors);
        }
    }

    public function test_401_maps_to_api_key_exception(): void
    {
        Http::fake(['*' => Http::response(['mensagem' => 'API key inválida.'], 401)]);

        $this->expectException(ApiKeyException::class);
        $this->client()->getSubscription('uuid-x');
    }

    public function test_404_maps_to_subscription_not_found(): void
    {
        Http::fake(['*' => Http::response(['mensagem' => 'Assinatura não encontrada.'], 404)]);

        $this->expectException(SubscriptionNotFoundException::class);
        $this->client()->getSubscriptionByReference('kicol_customer_999');
    }

    public function test_unmapped_status_falls_back_to_generic_exception(): void
    {
        Http::fake(['*' => Http::response(['mensagem' => 'Erro interno.'], 500)]);

        $this->expectException(FullFlowException::class);
        $this->expectExceptionMessage('FullFlow API error (500)');
        $this->client()->getSubscription('uuid-x');
    }

    // ---- CL-2: getSubscriptionByReference ----

    public function test_get_subscription_by_reference_normalizes_real_resource_format(): void
    {
        // Fixture fiel ao SubscriptionResource real (capturado do staging em
        // 2026-06-05): o endpoint responde 'id', NÃO 'assinatura_id'.
        Http::fake([
            '*' => Http::response([
                'id' => '3f43cb5c-4eb3-46cb-9dc0-0b854e33ba4d',
                'referencia_externa' => 'kicol_customer_13',
                'status' => 'ativa',
                'valor' => 397,
                'periodicidade' => 'mensal',
                'dia_vencimento' => 10,
                'trial_ate' => '2026-06-05',
                'cliente' => ['documento' => '12345678000199', 'nome' => 'Loja X', 'email' => 'x@x.com'],
            ], 200),
        ]);

        $result = $this->client()->getSubscriptionByReference('kicol_customer_13');

        // Normalização: 'assinatura_id' espelhado de 'id' (mesmo nome que o
        // create retorna — reaproveitado pelo onboarding após 409).
        $this->assertSame('3f43cb5c-4eb3-46cb-9dc0-0b854e33ba4d', $result['assinatura_id']);
        $this->assertSame($result['id'], $result['assinatura_id']);
        $this->assertSame('ativa', $result['status']);
        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/assinaturas')
                && ($request->data()['referencia_externa'] ?? null) === 'kicol_customer_13';
        });
    }

    public function test_get_subscription_by_reference_does_not_override_existing_assinatura_id(): void
    {
        Http::fake([
            '*' => Http::response(['id' => 'uuid-a', 'assinatura_id' => 'uuid-b'], 200),
        ]);

        $result = $this->client()->getSubscriptionByReference('ref-x');

        $this->assertSame('uuid-b', $result['assinatura_id']);
    }

    // ---- CL-3: purchaseAddon com payment_method ----

    public function test_purchase_addon_defaults_payment_method_to_pix(): void
    {
        Http::fake(['*' => Http::response(['purchase_id' => 'p1', 'status' => 'pendente'], 201)]);

        // Retrocompat: call site v0.7 (3 argumentos) continua válido.
        $this->client()->purchaseAddon('sub_x', 'pacote_5k', 2);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->method() === 'POST'
                && str_contains($request->url(), '/addons/comprar')
                && $data['subscription_code'] === 'sub_x'
                && $data['addon_code'] === 'pacote_5k'
                && $data['quantity'] === 2
                && $data['payment_method'] === 'pix';
        });
    }

    public function test_purchase_addon_sends_explicit_payment_method(): void
    {
        Http::fake(['*' => Http::response(['purchase_id' => 'p1', 'status' => 'pendente'], 201)]);

        $this->client()->purchaseAddon('sub_x', 'pacote_5k', 1, 'card');

        Http::assertSent(fn ($request) => $request->data()['payment_method'] === 'card');
    }
}
