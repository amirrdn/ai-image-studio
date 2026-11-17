<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.base_url' => 'https://pixelforge.test']);
        config(['pixelforge.api_key' => 'pf_admin_key']);
    }

    public function test_first_registered_user_becomes_admin_and_subsequent_users_do_not(): void
    {
        $this->post('/register', [
            'name' => 'First Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect('/dashboard');

        $firstUser = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue($firstUser->is_admin);

        $this->post('/logout');

        $this->post('/register', [
            'name' => 'Second User',
            'email' => 'user@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect('/dashboard');

        $secondUser = User::where('email', 'user@example.com')->firstOrFail();
        $this->assertFalse($secondUser->is_admin);
    }

    public function test_non_admin_cannot_access_admin_report(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get('/admin/report')
            ->assertStatus(403);
    }

    public function test_admin_report_aggregates_spend_provider_cost_and_profit_with_bcmath(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/usage*' => Http::response([
                'object' => 'usage',
                'total' => [
                    'generations' => 2,
                    'cost_usd' => '0.120000000',
                ],
            ], 200),
        ]);

        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);

        Generation::create([
            'user_id' => $customer->id,
            'pixelforge_id' => 'gen_rep_1',
            'prompt' => 'Image 1',
            'size' => '1024x1024',
            'quality' => 'medium',
            'status' => Generation::STATUS_SUCCEEDED,
            'pricing_snapshot' => PricingService::DEFAULT_RATES,
            'estimated_provider_cost_min' => '0.050000000',
            'estimated_provider_cost_max' => '0.060000000',
            'estimated_user_cost_min' => '0.062500000',
            'estimated_user_cost_max' => '0.075000000',
            'actual_provider_cost' => '0.080000000',
            'actual_user_cost' => '0.100000000',
        ]);

        Generation::create([
            'user_id' => $customer->id,
            'pixelforge_id' => 'gen_rep_2',
            'prompt' => 'Image 2',
            'size' => '1024x1024',
            'quality' => 'low',
            'status' => Generation::STATUS_SUCCEEDED,
            'pricing_snapshot' => PricingService::DEFAULT_RATES,
            'estimated_provider_cost_min' => '0.030000000',
            'estimated_provider_cost_max' => '0.040000000',
            'estimated_user_cost_min' => '0.037500000',
            'estimated_user_cost_max' => '0.050000000',
            'actual_provider_cost' => '0.040000000',
            'actual_user_cost' => '0.050000000',
        ]);

        Generation::create([
            'user_id' => $customer->id,
            'pixelforge_id' => 'gen_rep_3',
            'prompt' => 'Image 3 failed',
            'size' => '1024x1024',
            'quality' => 'high',
            'status' => Generation::STATUS_FAILED,
            'pricing_snapshot' => PricingService::DEFAULT_RATES,
            'estimated_provider_cost_min' => '0.030000000',
            'estimated_provider_cost_max' => '0.040000000',
            'estimated_user_cost_min' => '0.037500000',
            'estimated_user_cost_max' => '0.050000000',
            'actual_provider_cost' => '0.000000000',
            'actual_user_cost' => '0.000000000',
        ]);

        // JSON response check
        $this->actingAs($admin)
            ->getJson('/admin/report')
            ->assertOk()
            ->assertJsonPath('summary.total_generations', 3)
            ->assertJsonPath('summary.succeeded_generations', 2)
            ->assertJsonPath('summary.failed_generations', 1)
            ->assertJsonPath('summary.total_user_cost', '0.150000000')
            ->assertJsonPath('summary.total_provider_cost', '0.120000000')
            ->assertJsonPath('summary.total_profit', '0.030000000')
            ->assertJsonPath('by_user.0.total_user_cost', '0.150000000')
            ->assertJsonPath('by_user.0.profit', '0.030000000')
            ->assertJsonPath('pixelforge_usage.total.cost_usd', '0.120000000');

        // Inertia page response check
        $this->actingAs($admin)
            ->get('/admin/report')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Report')
                ->where('summary.total_profit', '0.030000000')
                ->has('daily', 1)
                ->has('by_user', 1)
            );
    }
}
