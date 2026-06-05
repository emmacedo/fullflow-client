<?php

namespace Kicol\FullFlow\Tests\Integration;

use Kicol\FullFlow\Exceptions\InvalidPayloadException;
use Kicol\FullFlow\Exceptions\SubscriptionNotFoundException;
use Kicol\FullFlow\FullFlowClient;
use Orchestra\Testbench\TestCase;

/**
 * Contrato real contra o FullFlow de STAGING (Etapa 8 da migração).
 * Trava dupla: além das envs de URL/key, exige opt-in explícito —
 * impossível disparar chamada real por acidente (CI, env sujo):
 *
 *   FULLFLOW_RUN_STAGING_CONTRACTS=1 \
 *   FULLFLOW_STAGING_URL=https://stg.fullflow.app.br/api/v1 \
 *   FULLFLOW_STAGING_KEY=ff_live_xxx \
 *   FULLFLOW_STAGING_REFERENCE=STAGING_TEST_UPGRADE_PIX \
 *   vendor/bin/phpunit tests/Integration
 *
 * Todos os cenários são read-only ou 422 sem side effects (card não é
 * aceito — validação rejeita antes de qualquer escrita).
 */
class StagingContractTest extends TestCase
{
    private function client(): FullFlowClient
    {
        if (getenv('FULLFLOW_RUN_STAGING_CONTRACTS') !== '1') {
            $this->markTestSkipped('Opt-in FULLFLOW_RUN_STAGING_CONTRACTS=1 ausente — contrato de staging pulado.');
        }

        $url = getenv('FULLFLOW_STAGING_URL');
        $key = getenv('FULLFLOW_STAGING_KEY');

        if (! $url || ! $key) {
            $this->markTestSkipped('Envs FULLFLOW_STAGING_URL/KEY não definidas — teste de integração pulado.');
        }

        return new FullFlowClient($url, $key);
    }

    public function test_get_subscription_by_reference_returns_real_subscription(): void
    {
        $reference = getenv('FULLFLOW_STAGING_REFERENCE') ?: 'STAGING_TEST_UPGRADE_PIX';

        $sub = $this->client()->getSubscriptionByReference($reference);

        // Formato real do SubscriptionResource ('id' na raiz, sem wrapping) +
        // normalização do client ('assinatura_id' espelhado). Asserts
        // estritos, sem coalesce — um '??' aqui já mascarou o mismatch
        // id/assinatura_id uma vez.
        $this->assertNotEmpty($sub['id']);
        $this->assertSame($sub['id'], $sub['assinatura_id']);
        $this->assertSame($reference, $sub['referencia_externa']);
        $this->assertNotEmpty($sub['status']);
    }

    public function test_get_subscription_by_unknown_reference_throws_not_found(): void
    {
        $this->expectException(SubscriptionNotFoundException::class);
        $this->client()->getSubscriptionByReference('REFERENCIA_INEXISTENTE_ETAPA8');
    }

    public function test_purchase_addon_with_invalid_payment_method_throws_422(): void
    {
        try {
            // 'card' é rejeitado pela validação (in:pix) ANTES de qualquer
            // escrita — zero side effects (provado na validação da Etapa 5).
            $this->client()->purchaseAddon('SUB_INEXISTENTE', 'pacote_x', 1, 'card');
            $this->fail('Esperava InvalidPayloadException (422).');
        } catch (InvalidPayloadException $e) {
            $this->assertArrayHasKey('payment_method', $e->errors);
        }
    }
}
