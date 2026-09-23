<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.10 — preço de tabela do plano (o "de" do "de/por"), que a API já
 * devolvia e a replicação descartava. Condicional porque um SaaS pode ter
 * criado a coluna por migration própria antes de o pacote a trazer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fullflow_plans', 'list_amount')) {
            return;
        }

        Schema::table('fullflow_plans', function (Blueprint $table) {
            $table->decimal('list_amount', 10, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fullflow_plans', 'list_amount')) {
            return;
        }

        Schema::table('fullflow_plans', function (Blueprint $table) {
            $table->dropColumn('list_amount');
        });
    }
};
