<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colunas do espelho de planos exigidas pelo fluxo plan.updated (v0.8):
 *
 *  - plan_version: gate de ordem (camada 2 da idempotência) — webhook com
 *    versão <= local é descartado (sincronizacao-real-time 4.4).
 *  - active: planos que somem do GET /planos (desativados no FullFlow) são
 *    marcados inativos em vez de deletados (reconcile 4.10) — preserva
 *    histórico para assinaturas antigas que referenciam o code.
 *
 * Auto-load do pacote — a fase F2 do KicolApps NÃO deve recriar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fullflow_plans', function (Blueprint $table) {
            $table->unsignedInteger('plan_version')->default(0);
            $table->boolean('active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('fullflow_plans', function (Blueprint $table) {
            $table->dropColumn(['plan_version', 'active']);
        });
    }
};
