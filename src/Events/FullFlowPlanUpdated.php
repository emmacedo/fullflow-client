<?php

namespace Kicol\FullFlow\Events;

/**
 * Disparado APÓS o PlanUpdatedHandler aplicar (commitar) uma mudança de
 * plano no espelho local. O SaaS escuta para reagir: limpar cache de
 * catálogo, recalcular cotas em cache, invalidar gates etc.
 */
class FullFlowPlanUpdated extends AbstractWebhookEvent
{
}
