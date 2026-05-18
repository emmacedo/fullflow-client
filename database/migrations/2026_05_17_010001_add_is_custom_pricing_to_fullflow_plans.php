<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fullflow_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('fullflow_plans', 'is_custom_pricing')) {
                $table->boolean('is_custom_pricing')->default(false)->after('amount');
            }
        });

        if (Schema::hasColumn('fullflow_plans', 'amount')) {
            Schema::table('fullflow_plans', function (Blueprint $table) {
                $table->decimal('amount', 10, 2)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('fullflow_plans', function (Blueprint $table) {
            $table->dropColumn('is_custom_pricing');
            $table->decimal('amount', 10, 2)->default(0)->change();
        });
    }
};
