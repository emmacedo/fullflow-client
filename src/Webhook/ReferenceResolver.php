<?php

namespace Kicol\FullFlow\Webhook;

use Closure;

/**
 * Resolve a external_reference de webhooks/assinaturas para store_config_id
 * durante a janela de migração (CL-5):
 *
 *  - Formato novo:  "kicol_store_123"    → 123 (direto)
 *  - Formato legado: "kicol_customer_456" → delegado ao resolver registrado
 *    pelo SaaS (só ele sabe mapear customer → store_config). Sem resolver
 *    registrado, retorna null.
 *  - Qualquer outro formato → null.
 *
 * O SaaS registra o mapeamento legado no boot do AppServiceProvider:
 *
 *   ReferenceResolver::resolveLegacyCustomerUsing(
 *       fn (int $customerId) => StoreConfig::where('customer_id', $customerId)->value('id')
 *   );
 *
 * Registro em runtime (não em config) — closures quebrariam config:cache.
 */
class ReferenceResolver
{
    protected static ?Closure $legacyCustomerResolver = null;

    public static function resolveLegacyCustomerUsing(Closure $resolver): void
    {
        static::$legacyCustomerResolver = $resolver;
    }

    /** @internal somente para testes — remove o resolver registrado. */
    public static function forgetLegacyCustomerResolver(): void
    {
        static::$legacyCustomerResolver = null;
    }

    public static function storeConfigId(string $reference): ?int
    {
        if (preg_match('/^kicol_store_(\d+)$/', $reference, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/^kicol_customer_(\d+)$/', $reference, $m)) {
            if (static::$legacyCustomerResolver === null) {
                return null;
            }

            $resolved = (static::$legacyCustomerResolver)((int) $m[1]);

            return $resolved !== null ? (int) $resolved : null;
        }

        return null;
    }
}
