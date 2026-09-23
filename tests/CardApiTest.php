<?php

namespace Kicol\FullFlow\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kicol\FullFlow\Exceptions\CardException;
use Kicol\FullFlow\Exceptions\CardUnavailableException;
use Kicol\FullFlow\Exceptions\LastCardException;
use Kicol\FullFlow\Exceptions\SubscriptionNotFoundException;
use Kicol\FullFlow\FullFlowClient;
use Orchestra\Testbench\TestCase;

/**
 * v0.10 — cartão de crédito, plataforma e pacote adicional no cartão: o que
 * cada método envia (caminho, verbo, corpo) e como os erros do módulo de
 * cartão viram exceções que o SaaS consegue tratar sem ler o corpo cru.
 */
class CardApiTest extends TestCase
{
    private function client(): FullFlowClient
    {
        return new FullFlowClient('https://fullflow.test/api/v1', 'test-key');
    }

    private const ACEITE = ['versao' => 'v1', 'ip' => '191.0.0.1', 'user_agent' => 'UA'];

    public function test_open_card_checkout_sends_consent_and_return_url(): void
    {
        Http::fake(['*' => Http::response([
            'cobranca_id' => 'uuid-c', 'valor' => 79.9, 'vencimento' => '2026-10-01',
            'checkout' => ['client_secret' => 'cs_test_x_secret_y', 'chave_publicavel' => 'pk_test'],
            'cartao_salvo' => null,
        ])]);

        $r = $this->client()->openCardCheckout('sub-1', self::ACEITE, 'https://saas.test/volta');

        $this->assertSame('cs_test_x_secret_y', $r['checkout']['client_secret']);
        Http::assertSent(fn (Request $req) => $req->method() === 'POST'
            && str_ends_with($req->url(), '/assinaturas/sub-1/checkout-cartao')
            && $req['consentimento']['versao'] === 'v1'
            && $req['consentimento']['ip'] === '191.0.0.1'
            && $req['url_retorno'] === 'https://saas.test/volta');
    }

    public function test_open_card_checkout_without_return_url_omits_the_field(): void
    {
        Http::fake(['*' => Http::response(['checkout' => []])]);

        $this->client()->openCardCheckout('sub-1', self::ACEITE);

        Http::assertSent(fn (Request $req) => ! array_key_exists('url_retorno', $req->data()));
    }

    public static function cardCodes(): array
    {
        return [
            ['sem_cobranca_em_aberto'],
            ['cobranca_nao_elegivel'],
            ['assinatura_sem_plano_de_cobranca'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cardCodes')]
    public function test_card_module_409_codes_become_card_exception_with_code(string $codigo): void
    {
        Http::fake(['*' => Http::response(['codigo' => $codigo, 'mensagem' => "erro {$codigo}"], 409)]);

        try {
            $this->client()->openCardCheckout('sub-1', self::ACEITE);
            $this->fail("Esperava CardException para {$codigo}.");
        } catch (CardException $e) {
            $this->assertSame($codigo, $e->codigo);
            $this->assertSame("erro {$codigo}", $e->getMessage());
            $this->assertNotInstanceOf(LastCardException::class, $e);
        }
    }

    public function test_503_card_unavailable_is_its_own_exception(): void
    {
        Http::fake(['*' => Http::response(['codigo' => 'cartao_indisponivel', 'mensagem' => 'indisponível'], 503)]);

        $this->expectException(CardUnavailableException::class);
        $this->client()->openCardSetup('sub-1', self::ACEITE);
    }

    public function test_list_cards_hits_the_cards_path(): void
    {
        Http::fake(['*' => Http::response(['cartoes' => [['id' => 7, 'padrao' => true]]])]);

        $r = $this->client()->listCards('sub-1');

        $this->assertSame(7, $r['cartoes'][0]['id']);
        Http::assertSent(fn (Request $req) => $req->method() === 'GET' && str_ends_with($req->url(), '/assinaturas/sub-1/cartoes'));
    }

    public function test_set_default_card_posts_to_padrao(): void
    {
        Http::fake(['*' => Http::response(['cartao' => ['id' => 7, 'padrao' => true]])]);

        $this->client()->setDefaultCard('sub-1', 7);

        Http::assertSent(fn (Request $req) => $req->method() === 'POST' && str_ends_with($req->url(), '/assinaturas/sub-1/cartoes/7/padrao'));
    }

    public function test_remove_last_card_asks_before_and_confirm_sends_flag(): void
    {
        // Um stub por chamada: o Laravel executa todos os stubs que casam com
        // a URL, então dois fake() no mesmo teste não se substituem.
        Http::fake(['*' => Http::sequence()
            ->push(['codigo' => 'ultimo_cartao', 'mensagem' => 'É o último cartão.', 'confirmavel' => true], 409)
            ->push(['removido' => true], 200)]);

        try {
            $this->client()->removeCard('sub-1', 7);
            $this->fail('Esperava LastCardException.');
        } catch (LastCardException $e) {
            $this->assertSame('ultimo_cartao', $e->codigo);
        }

        $r = $this->client()->removeCard('sub-1', 7, confirm: true);

        $this->assertTrue($r['removido']);
        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE'
            && str_ends_with($req->url(), '/assinaturas/sub-1/cartoes/7')
            && ($req['confirmar'] ?? null) === true);
    }

    public function test_card_not_found_is_card_exception_not_subscription_not_found(): void
    {
        Http::fake(['*' => Http::response(['codigo' => 'cartao_nao_encontrado', 'mensagem' => 'não é seu'], 404)]);

        try {
            $this->client()->removeCard('sub-1', 99);
            $this->fail('Esperava CardException.');
        } catch (CardException $e) {
            $this->assertSame('cartao_nao_encontrado', $e->codigo);
        }
    }

    public function test_404_without_card_code_is_still_subscription_not_found(): void
    {
        Http::fake(['*' => Http::response(['codigo' => 'assinatura_nao_encontrada', 'mensagem' => 'x'], 404)]);

        $this->expectException(SubscriptionNotFoundException::class);
        $this->client()->listCards('sub-x');
    }

    public function test_set_platform_sends_null_to_clear(): void
    {
        Http::fake(['*' => Http::response(['plataforma' => null])]);

        $this->client()->setPlatform('sub-1', null);

        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/assinaturas/sub-1/plataforma')
            && array_key_exists('plataforma', $req->data()) && $req['plataforma'] === null);
    }

    public function test_purchase_addon_with_card_and_consent_sends_the_new_fields(): void
    {
        Http::fake(['*' => Http::response(['purchase_id' => 'p1', 'status' => 'confirmada', 'payment_method' => 'card'], 201)]);

        $this->client()->purchaseAddon('ref-1', 'pacote_5k', 2, 'card', guardarCartao: true, consentimento: self::ACEITE);

        Http::assertSent(fn (Request $req) => $req['payment_method'] === 'card'
            && $req['quantity'] === 2
            && $req['guardar_cartao'] === true
            && $req['consentimento']['versao'] === 'v1');
    }

    public function test_purchase_addon_legacy_call_sends_no_card_fields(): void
    {
        Http::fake(['*' => Http::response(['purchase_id' => 'p1'], 201)]);

        $this->client()->purchaseAddon('ref-1', 'pacote_5k');

        Http::assertSent(fn (Request $req) => $req['payment_method'] === 'pix'
            && ! array_key_exists('guardar_cartao', $req->data())
            && ! array_key_exists('consentimento', $req->data()));
    }
}
