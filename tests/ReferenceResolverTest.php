<?php

namespace Kicol\FullFlow\Tests;

use Kicol\FullFlow\Webhook\ReferenceResolver;
use PHPUnit\Framework\TestCase;

/**
 * Resolução de external_reference → store_config_id (CL-5 da migração):
 * formato novo direto, formato legado via resolver registrado pelo SaaS,
 * null para desconhecido.
 */
class ReferenceResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        ReferenceResolver::forgetLegacyCustomerResolver();
        parent::tearDown();
    }

    public function test_new_format_resolves_directly(): void
    {
        $this->assertSame(123, ReferenceResolver::storeConfigId('kicol_store_123'));
    }

    public function test_legacy_format_without_registered_resolver_returns_null(): void
    {
        $this->assertNull(ReferenceResolver::storeConfigId('kicol_customer_456'));
    }

    public function test_legacy_format_delegates_to_registered_resolver(): void
    {
        ReferenceResolver::resolveLegacyCustomerUsing(function (int $customerId): ?int {
            return $customerId === 456 ? 789 : null;
        });

        $this->assertSame(789, ReferenceResolver::storeConfigId('kicol_customer_456'));
    }

    public function test_legacy_resolver_returning_null_propagates_null(): void
    {
        // Customer sem store_config (ex.: cadastro incompleto).
        ReferenceResolver::resolveLegacyCustomerUsing(fn (int $customerId): ?int => null);

        $this->assertNull(ReferenceResolver::storeConfigId('kicol_customer_999'));
    }

    public function test_unknown_formats_return_null(): void
    {
        $this->assertNull(ReferenceResolver::storeConfigId('STAGING_TEST_UPGRADE_PIX'));
        $this->assertNull(ReferenceResolver::storeConfigId('kicol_store_abc'));
        $this->assertNull(ReferenceResolver::storeConfigId('kicol_store_12x'));
        $this->assertNull(ReferenceResolver::storeConfigId(''));
    }
}
