<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stub publicável (CL-5 da migração): adiciona a âncora store_config_id à
 * tabela de assinaturas. Nullable de propósito — registros existentes são
 * backfilled depois (forStore() do client tem fallback dual na janela).
 *
 * A FK referencia store_configs (KicolApps). SaaS sem esse conceito deve
 * remover a constraint ao publicar — a coluna + index bastam para o client.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('fullflow.subscriptions_table', 'fullflow_subscriptions');

        Schema::table($tableName, function (Blueprint $table) {
            // Sem ->after(): a coluna de tenant varia por SaaS (user_id,
            // customer_id...) — posição fixa quebraria a migration publicada.
            $table->unsignedBigInteger('store_config_id')->nullable();
            $table->index('store_config_id');
            $table->foreign('store_config_id')->references('id')->on('store_configs');
        });
    }

    public function down(): void
    {
        $tableName = config('fullflow.subscriptions_table', 'fullflow_subscriptions');

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropForeign(['store_config_id']);
            $table->dropIndex(['store_config_id']);
            $table->dropColumn('store_config_id');
        });
    }
};
