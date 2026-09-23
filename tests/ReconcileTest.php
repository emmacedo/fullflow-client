<?php

namespace Kicol\FullFlow\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Kicol\FullFlow\FullFlowServiceProvider;
use Kicol\FullFlow\Models\FullFlowSubscription;
use Orchestra\Testbench\TestCase;

/**
 * v0.10 — fullflow:reconcile grava plano e periodicidade. A troca de plano
 * feita no FullFlow (painel, redução agendada aplicada na renovação) não tem
 * webhook próprio; sem isto o espelho local ficava com o plano antigo,
 * liberando módulos que o cliente deixou de pagar.
 */
class ReconcileTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FullFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('fullflow.base_url', 'https://fullflow.test/api/v1');
        $app['config']->set('fullflow.api_key', 'k');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        Schema::create('fullflow_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('fullflow_id')->unique();
            $table->string('reference')->unique();
            $table->string('plan_code')->nullable();
            $table->string('status');
            $table->date('trial_until')->nullable();
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('billing_cycle')->default('mensal');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    private function local(): FullFlowSubscription
    {
        return FullFlowSubscription::create([
            'user_id' => 1, 'fullflow_id' => '11111111-1111-1111-1111-111111111111', 'reference' => 'ref-1',
            'plan_code' => 'pro', 'status' => 'ativa', 'amount' => 79.90, 'billing_cycle' => 'mensal',
        ]);
    }

    public function test_reconcile_updates_plan_code_and_billing_cycle_from_the_api(): void
    {
        $sub = $this->local();
        Http::fake(['*' => Http::response([
            'id' => $sub->fullflow_id, 'status' => 'ativa', 'plan_code' => 'basico', 'valor' => 29.90,
            'periodicidade' => 'anual', 'trial_ate' => null,
            'inicio_periodo_atual' => '2026-09-10', 'fim_periodo_atual' => '2026-10-09',
        ])]);

        $this->artisan('fullflow:reconcile')->assertSuccessful();

        $sub->refresh();
        $this->assertSame('basico', $sub->plan_code);
        $this->assertSame('anual', $sub->billing_cycle);
        $this->assertEqualsWithDelta(29.90, (float) $sub->amount, 0.001);
        $this->assertSame('2026-10-09', $sub->current_period_end->toDateString());
    }

    public function test_reconcile_keeps_local_plan_when_the_api_omits_it(): void
    {
        $sub = $this->local();
        Http::fake(['*' => Http::response(['id' => $sub->fullflow_id, 'status' => 'past_due', 'valor' => 79.90])]);

        $this->artisan('fullflow:reconcile')->assertSuccessful();

        $sub->refresh();
        $this->assertSame('pro', $sub->plan_code, 'resposta antiga sem plan_code não apaga o plano local');
        $this->assertSame('mensal', $sub->billing_cycle);
        $this->assertSame('past_due', $sub->status);
    }
}
