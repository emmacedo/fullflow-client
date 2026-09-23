<?php

namespace Kicol\FullFlow\Events;

/**
 * `addon.estornado` — compra de pacote adicional estornada. Mesmo `dados` do
 * `addon.confirmado` (purchase_id, addon_code, module_code, feature_key,
 * quantity, credits, total_amount, payment_method); o SaaS abate o saldo.
 */
class AddonRefunded extends AbstractWebhookEvent {}
