<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('fullflow.subscriptions_table', 'fullflow_subscriptions');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('fullflow_id')->unique();
            $table->string('reference')->unique();
            $table->string('plan_code')->nullable();
            $table->string('status');
            $table->date('trial_until')->nullable();
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('billing_cycle');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        $tableName = config('fullflow.subscriptions_table', 'fullflow_subscriptions');
        Schema::dropIfExists($tableName);
    }
};
