# kicol/fullflow-client

Cliente PHP/Laravel oficial para integrar SaaS Kicol ao **FullFlow** — módulo de Assinaturas (cobrança recorrente via Asaas, gerenciada pelo FullFlow).

Encapsula:
- Cliente HTTP autenticado por API key
- Validação HMAC-SHA256 de webhooks
- Idempotência por `evento_id`
- Replay protection por timestamp
- Eventos Laravel para reagir a transições
- Middleware de bloqueio por status
- Comando de reconciliação periódica
- Migration do schema local sugerido

> Documentação completa do contrato da API e dos webhooks: ver [`fullflow-saas-integration-guide.md`](https://fullflow.app.br/docs) (cópia interna do guia).

## Instalação

Configurar repositório (até estar no Packagist):

```json
// composer.json do SaaS
"repositories": [
    {
        "type": "path",
        "url": "../fullflow-client"
    }
]
```

Instalar:

```bash
composer require kicol/fullflow-client:dev-main
```

Publicar config (opcional, para customizar) e migration:

```bash
php artisan vendor:publish --tag=fullflow-config
php artisan vendor:publish --tag=fullflow-migrations
php artisan migrate
```

## Configuração

`.env`:

```dotenv
FULLFLOW_BASE_URL=https://fullflow.app.br/api/v1
FULLFLOW_API_KEY=ff_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
FULLFLOW_WEBHOOK_SECRET=whsec_yyyyyyyyyyyyyyyyyyyyyy
FULLFLOW_TIMEOUT=15
FULLFLOW_REPLAY_MINUTES=5
FULLFLOW_IDEMPOTENCY_TTL_HOURS=24
```

## Uso — chamar a API

```php
use Kicol\FullFlow\Facades\FullFlow;
use Kicol\FullFlow\Exceptions\SubscriptionAlreadyExistsException;

try {
    $result = FullFlow::createSubscription([
        'referencia_externa' => "kimobe_sub_{$user->id}",
        'cliente' => [
            'tipo' => 'pj',
            'documento' => preg_replace('/\D/', '', $user->document),
            'nome' => $user->name,
            'email' => $user->email,
            // ... endereço opcional
        ],
        'assinatura' => [
            'periodicidade' => 'mensal',
            'valor' => 299.90,
            'dia_vencimento' => 10,
            'com_trial' => true,
        ],
    ]);

    // $result = ['assinatura_id' => 'uuid', 'status' => 'trial', 'trial_ate' => '...']
    \App\Models\FullFlowSubscription::create([
        'user_id' => $user->id,
        'fullflow_id' => $result['assinatura_id'],
        'reference' => "kimobe_sub_{$user->id}",
        'status' => $result['status'],
        'trial_until' => $result['trial_ate'],
        'amount' => 299.90,
        'billing_cycle' => 'mensal',
    ]);
} catch (SubscriptionAlreadyExistsException) {
    // ref duplicada — usuário já tem assinatura
}
```

Outros métodos:

```php
FullFlow::getSubscription($uuid);
FullFlow::cancelSubscription($uuid, 'Cliente solicitou via chat');
FullFlow::reactivateSubscription($uuid);
FullFlow::listClientSubscriptions('12345678000100');
FullFlow::setPlatform($uuid, 'tray');
```

### Cartão de crédito (v0.10)

O FullFlow devolve um **segredo de sessão**, não uma URL: o SaaS monta o
formulário do provedor num modal (Stripe Embedded Checkout com a
`chave_publicavel`) e o lojista não sai do sistema. Nenhum dado de cartão passa
pelo SaaS. O resultado do pagamento chega pelos webhooks de assinatura.

```php
use Kicol\FullFlow\Exceptions\CardException;
use Kicol\FullFlow\Exceptions\CardUnavailableException;
use Kicol\FullFlow\Exceptions\LastCardException;

$aceite = ['versao' => 'v1', 'ip' => $ipDoLojista, 'user_agent' => $request->userAgent()];

try {
    // Pagar a cobrança em aberto com cartão (o cartão fica salvo: débito automático)
    $r = FullFlow::openCardCheckout($uuid, $aceite, route('assinatura', ['pagamento' => 1]));
    // $r['checkout']['client_secret'], $r['checkout']['chave_publicavel'], $r['cartao_salvo']
} catch (CardUnavailableException) {
    // sem provedor de cartão no momento — manter boleto/Pix
} catch (CardException $e) {
    // $e->codigo: sem_cobranca_em_aberto | cobranca_nao_elegivel | assinatura_sem_plano_de_cobranca
}

FullFlow::listCards($uuid);                       // ['cartoes' => [...]] — o padrão primeiro
FullFlow::openCardSetup($uuid, $aceite, $url);    // cadastrar sem cobrança (mesmo checkout)
FullFlow::setDefaultCard($uuid, $cartaoId);

try {
    FullFlow::removeCard($uuid, $cartaoId);
} catch (LastCardException) {
    // último cartão de quem está em débito automático: perguntar e repetir com confirm
    FullFlow::removeCard($uuid, $cartaoId, confirm: true);
}

// Pacote adicional no cartão salvo: sai pago na hora (sem `checkout` na resposta);
// sem cartão salvo vem `checkout` para o formulário.
FullFlow::purchaseAddon($ref, 'pacote_5k', 1, 'card');
FullFlow::purchaseAddon($ref, 'pacote_5k', 1, 'card', guardarCartao: true, consentimento: $aceite);
```

O aceite é obrigatório sempre que o cartão pode ficar guardado. `ip` e
`user_agent` são do **lojista** (o servidor do SaaS é quem chama o FullFlow, e
registrar o endereço dele daria uma falsa prova). O texto do aviso é do SaaS;
`versao` identifica qual texto foi mostrado.

## Receber webhooks

Em `routes/api.php` ou `routes/web.php`:

```php
use Kicol\FullFlow\Http\Controllers\FullFlowWebhookController;

Route::post('/webhooks/fullflow', FullFlowWebhookController::class);
```

(Sem CSRF; sem autenticação — validação é por HMAC.)

O controller automaticamente:
1. Valida assinatura HMAC (header `X-Fullflow-Signature`)
2. Valida replay (header `X-Fullflow-Timestamp`, tolerância 5 min)
3. Dedupe por `evento_id` (cache 24h)
4. Dispatcha evento Laravel correspondente

## Reagir a eventos

Crie listeners para os eventos relevantes:

```php
// app/Listeners/HandleFullFlowActivation.php
use Kicol\FullFlow\Events\SubscriptionActivated;

class HandleFullFlowActivation
{
    public function handle(SubscriptionActivated $event): void
    {
        $sub = \App\Models\FullFlowSubscription::where('fullflow_id', $event->subscriptionId())->first();
        $sub?->update([
            'status' => 'ativa',
            'current_period_start' => $event->data()['inicio_periodo'] ?? null,
            'current_period_end' => $event->data()['fim_periodo'] ?? null,
        ]);
    }
}
```

Registre em `EventServiceProvider`:

```php
protected $listen = [
    \Kicol\FullFlow\Events\SubscriptionActivated::class => [
        \App\Listeners\HandleFullFlowActivation::class,
    ],
    \Kicol\FullFlow\Events\SubscriptionSuspended::class => [
        \App\Listeners\HandleFullFlowSuspension::class,
    ],
    // ...
];
```

Eventos disponíveis:
- `SubscriptionTrialStarted`
- `SubscriptionActivated`
- `SubscriptionPaymentReceived`
- `SubscriptionPastDue`
- `SubscriptionSuspended`
- `SubscriptionReactivated`
- `SubscriptionCancellationScheduled`
- `SubscriptionEnded`

Cada um expõe `eventId()`, `subscriptionId()`, `externalReference()`, `timestamp()`, `data()`.

Eventos disponíveis (`Kicol\FullFlow\Events\*`): `SubscriptionTrialStarted`,
`SubscriptionActivated`, `SubscriptionPastDue`, `SubscriptionSuspended`,
`SubscriptionReactivated`, `SubscriptionCancellationScheduled`,
`SubscriptionEnded`, `SubscriptionPaymentReceived`, `SubscriptionTrialExtended`
(v0.10), `AddonConfirmed`, `AddonRefunded` (v0.10) e `FullFlowPlanUpdated`.

## Bloquear acesso por status

No model User:

```php
use Kicol\FullFlow\Models\Concerns\HasFullFlowSubscription;

class User extends Authenticatable
{
    use HasFullFlowSubscription;
}
```

Em rotas:

```php
Route::middleware(['auth', 'fullflow.subscription.active'])->group(function () {
    // rotas que exigem assinatura em status liberador
});
```

Status que liberam (configurável em `config/fullflow.php`):
`trial`, `ativa`, `past_due`, `cancelamento_agendado`.

Resposta padrão:
- `expectsJson()` → `402 {error: subscription_blocked, status: ...}`
- Senão → `redirect()->route('subscription.blocked')`

## Reconciliação periódica

O comando `fullflow:reconcile` itera assinaturas locais não-canceladas e sincroniza status com o FullFlow. Útil contra perda de webhook.

```bash
php artisan fullflow:reconcile
php artisan fullflow:reconcile --limit=50
```

Agende em `app/Console/Kernel.php`:

```php
$schedule->command('fullflow:reconcile')->everySixHours();
```

Comportamento:
- Assinaturas marcadas como `cancelada` localmente são puladas
- Sincroniza status, teste, período, valor e — desde a v0.10 — `plan_code` e
  `billing_cycle` (a troca de plano feita no FullFlow não tem webhook próprio)
- Drift detectado é loggado como `warning` em `laravel.log`
- 404 do FullFlow → log + skip
- Erros de rede → log + segue

## Exceções

Todas estendem `Kicol\FullFlow\Exceptions\FullFlowException`:

- `ApiKeyException` (401)
- `SubscriptionNotFoundException` (404)
- `SubscriptionAlreadyExistsException` (409, ref duplicada)
- `InvalidTransitionException` (409, transição inválida — ex: cancelar assinatura já cancelada)
- `InvalidPayloadException` (400) — possui `$errors` com detalhes campo→erro
- `CardException` (409/404 do módulo de cartão) — possui `$codigo`
- `LastCardException` (409 `ultimo_cartao`) — repetir `removeCard` com `confirm: true`
- `CardUnavailableException` (503 `cartao_indisponivel`)
- `FullFlowException` (genérica)

## Schema local sugerido

A migration publicável cria `fullflow_subscriptions`:

| coluna | tipo |
|---|---|
| user_id | FK users |
| fullflow_id | UUID único |
| reference | string único |
| plan_code | string nullable |
| status | string |
| trial_until | date nullable |
| current_period_start | date nullable |
| current_period_end | date nullable |
| amount | decimal(12,2) |
| billing_cycle | string |
| last_synced_at | timestamp nullable |

Customize o nome da tabela com `FULLFLOW_SUBSCRIPTIONS_TABLE` no `.env`.

## Testes do package

```bash
cd fullflow-client
composer install
vendor/bin/phpunit
```

## Suporte

- E-mail: dev@kicol.com.br
- Issues: GitHub `kicol/fullflow-client`

## Licença

Proprietary — Kicol Tecnologia LTDA.
