<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fullflow_plans', 'visible_to_client')) {
            Schema::table('fullflow_plans', function (Blueprint $table) {
                $table->boolean('visible_to_client')->default(true)->after('is_custom_pricing');
            });
        }
    }

    public function down(): void
    {
        Schema::table('fullflow_plans', function (Blueprint $table) {
            $table->dropColumn('visible_to_client');
        });
    }
};
