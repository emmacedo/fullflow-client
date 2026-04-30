<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fullflow_plan_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fullflow_plan_id')->constrained('fullflow_plans')->cascadeOnDelete();
            $table->foreignId('fullflow_module_id')->constrained('fullflow_modules')->cascadeOnDelete();
            $table->unsignedInteger('quota_value')->nullable();
            $table->timestamps();

            $table->unique(['fullflow_plan_id', 'fullflow_module_id'], 'ff_plan_module_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fullflow_plan_modules');
    }
};
