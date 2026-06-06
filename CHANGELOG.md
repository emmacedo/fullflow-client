# Changelog — kicol/fullflow-client

## v0.8.0 — 2026-06-05

Versão do plano de migração KicolApps↔FullFlow (CL-1..CL-8). Projetada para
rodar **sem mudança de comportamento** sobre o schema atual (duais com
fallback legado) — o consumo pelo KicolApps acontece na fase F3 da migração.

### CL-1 — Mapeamento de erro 422
`FullFlowClient::handle()` mapeia `422` (validação Laravel, formato
`{message, errors}`) para `InvalidPayloadException`, além do `400` legado
(envelope `{codigo, mensagem, detalhes}`). Um único tipo de exception para
"payload rejeitado", independente da camada que rejeitou.

### CL-2 — `getSubscriptionByReference()`
Novo método: `GET /assinaturas?referencia_externa={ref}`. Normaliza
`assinatura_id = id` (o endpoint responde no formato do
`SubscriptionResource`; o create responde `assinatura_id` — o onboarding
reaproveita o mesmo nome após um 409).

### CL-3 — `purchaseAddon` com `payment_method`
4º parâmetro `paymentMethod` (default `'pix'`) enviado como
`payment_method`. Retrocompatível com call sites de 3 argumentos.

### CL-4 — `plan.updated` + idempotência durável + reconcile
- `PlanUpdatedHandler`: aplica o evento no espelho local com idempotência
  em 3 camadas (event_id no receiver, gate de `plan_version`, transação
  atômica). Dispara `FullFlowPlanUpdated` após o commit (invalidação de
  caches no SaaS). Payload sem `plan.code`/`plan_version` → 400 sem marcar
  processado (failed imediato + alerta no FullFlow, sem retry inútil).
- Migration auto-load `received_webhook_events` (idempotência durável —
  a fase F2 do KicolApps NÃO deve recriar). `event_id` VARCHAR(64): o
  produtor real emite UUID de 36 chars.
- Dedup por **máquina de estados** com claim atômico (`insertOrIgnore` na
  PK; `Cache::add` na janela pré-migrate): o evento só vira `processed`
  APÓS o side effect concluir. Duplicata em voo → **425** (retry com
  backoff no sender); claim de worker morto (stale, default 10 min,
  `FULLFLOW_IDEMPOTENCY_STALE_MINUTES`) → reclamado pela re-entrega;
  falha conhecida → release imediato. Crash entre claim e processamento
  não vira perda silenciosa de side effect (crédito de addon, status de
  assinatura).
- Migration auto-load `plan_version` + `active` em `fullflow_plans`.
- Comando `fullflow:catalog-reconcile` + schedule semanal (sábado,
  `FULLFLOW_RECONCILE_AT`, default 03:30) — rede de segurança do webhook.
- Validação opt-in de `product_code` (`FULLFLOW_PRODUCT_CODE`): payload de
  outro produto → 400 (sync 4.8 passo 3).

### CL-5 — `store_config_id` + `forStore()` dual + `ReferenceResolver`
- Stub publicável `add_store_config_id_to_fullflow_subscriptions`
  (nullable + index + FK; sem `->after()` — coluna de tenant varia).
- `forStore()`: primário por `store_config_id`, fallback `legacyForStore()`
  (hook sobrescrito pelo SaaS).
- `ReferenceResolver::storeConfigId()`: `kicol_store_X` direto;
  `kicol_customer_X` via closure registrada em runtime pelo SaaS.

### CL-6 — `getQuota()` com fonte dual
Se o plano tem linhas no espelho novo `fullflow_plan_features`
(`plan_id` + `feature_id` → `features.key`), ele é a autoridade — key
ausente retorna NULL sem cair no legado (não mistura fontes). Espelho
vazio/tabelas ausentes → `fullflow_plan_modules.quota_value` (idêntico
ao v0.7). Aliases slug legado→feature_key em
`config('fullflow.feature_key_aliases')`.

### CL-7 — Receiver aceita envelope PT e EN
`WebhookPayload` normaliza `evento_id/event_id`, `evento/event_type`,
`dados/data` etc. Accessors dos eventos são duais. Log de auditoria
`fullflow.webhook.received` com o formato detectado (critério de saída
do cutover B-coordenado, sync 4.9).

### CL-8 — Push do catálogo a partir das tabelas locais
`fullflow:catalog-sync` lê `modules`/`features`/`module_features` locais
(quando existem e populadas) e envia o contrato 3.3 EN completo via
`syncCatalog()`; fallback ao `config/fullflow.php` legado (formato PT)
para SaaS sem as tabelas. `pullCatalog()` também espelha
`fullflow_plan_features` (parser usa só `key`+`quota` do superset do GET)
e marca planos ausentes como `active=false` em vez de deletar.

## v0.7.0 e anteriores

Histórico anterior à adoção deste changelog — ver mensagens de commit.
