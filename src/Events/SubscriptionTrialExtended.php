<?php

namespace Kicol\FullFlow\Events;

/**
 * `assinatura.trial_estendido` — dias extras concedidos ao teste. Em `dados`:
 * trial_ate, trial_ate_anterior, dias_concedidos, motivo.
 */
class SubscriptionTrialExtended extends AbstractWebhookEvent {}
