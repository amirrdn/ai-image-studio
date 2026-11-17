<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['pixelforge.base_url' => 'https://pixelforge.test']);
        config(['pixelforge.api_key' => 'pf_test_key']);
    }

    public function test_dashboard_renders_user_generations_balance_and_pricing(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/pricing' => Http::response([
                'rates' => PricingService::DEFAULT_RATES,
            ], 200),
        ]);

        $user = User::factory()->create(['credit_balance' => '10.000000000']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('creditBalance', '10.000000000')
                ->has('generations', 0)
                ->has('pricing')
            );
    }

    public function test_estimate_endpoint_returns_realtime_cost_breakdown(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/pricing' => Http::response([], 500),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/generations/estimate', [
                'prompt' => 'abcd',
                'size' => '1024x1024',
                'quality' => 'medium',
            ])
            ->assertOk()
            ->assertJsonPath('tokens.input_text_tokens', 1)
            ->assertJsonPath('tokens.output_tokens', 1100)
            ->assertJsonPath('estimated_user_cost_min', '0.058125000')
            ->assertJsonPath('estimated_user_cost_max', '0.086262500');
    }

    public function test_generation_is_blocked_when_user_balance_is_insufficient(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/pricing' => Http::response([], 500),
        ]);

        // Max estimated cost for 1024x1024 medium is 0.086262500
        $user = User::factory()->create(['credit_balance' => '0.050000000']);

        $this->actingAs($user)
            ->postJson('/generations', [
                'prompt' => 'abcd',
                'size' => '1024x1024',
                'quality' => 'medium',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credit_balance']);

        $this->assertSame(0, Generation::count());
        Http::assertSentCount(1); // Only /v1/pricing, never /v1/generations
    }

    public function test_store_creates_local_generation_and_submits_to_pixelforge(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/pricing' => Http::response([
                'rates' => PricingService::DEFAULT_RATES,
            ], 200),
            'https://pixelforge.test/v1/generations' => Http::response([
                'id' => 'gen_remote_123',
                'status' => 'queued',
            ], 202),
        ]);

        $user = User::factory()->create(['credit_balance' => '10.000000000']);

        $this->actingAs($user)
            ->postJson('/generations', [
                'prompt' => 'A dramatic cyberpunk skyline',
                'size' => '1536x1024',
                'quality' => 'high',
                'reference_urls' => ['https://example.com/ref.png'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('generation.pixelforge_id', 'gen_remote_123')
            ->assertJsonPath('generation.status', 'queued');

        $generation = Generation::firstOrFail();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame('gen_remote_123', $generation->pixelforge_id);
        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
    }

    public function test_store_marks_generation_failed_with_zero_cost_when_pixelforge_returns_429(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/pricing' => Http::response([], 500),
            'https://pixelforge.test/v1/generations' => Http::response([
                'error' => [
                    'code' => 'rate_limit_exceeded',
                    'message' => 'Too many requests',
                ],
            ], 429, ['Retry-After' => '10']),
        ]);

        $user = User::factory()->create(['credit_balance' => '10.000000000']);

        $this->actingAs($user)
            ->postJson('/generations', [
                'prompt' => 'Rate limited prompt',
                'size' => '1024x1024',
                'quality' => 'low',
            ])
            ->assertStatus(429)
            ->assertJsonPath('generation.status', 'failed')
            ->assertJsonPath('generation.actual_user_cost', '0.000000000');

        $this->assertSame('10.000000000', $user->fresh()->credit_balance);
    }

    public function test_sync_polling_updates_generation_and_deducts_balance_once(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/generations/gen_poll_001' => Http::response([
                'id' => 'gen_poll_001',
                'status' => 'succeeded',
                'image_url' => 'https://cdn.pixelforge.test/poll.png',
                'image_url_expires_at' => '2026-11-18T12:00:00Z',
                'usage' => [
                    'input_text_tokens' => 4,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 200,
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['credit_balance' => '10.000000000']);
        $otherUser = User::factory()->create();

        $generation = Generation::create([
            'user_id' => $user->id,
            'pixelforge_id' => 'gen_poll_001',
            'prompt' => 'Polling test',
            'size' => '1024x1024',
            'quality' => 'medium',
            'status' => Generation::STATUS_PROCESSING,
            'pricing_snapshot' => PricingService::DEFAULT_RATES,
            'estimated_provider_cost_min' => '0.046500000',
            'estimated_provider_cost_max' => '0.069010000',
            'estimated_user_cost_min' => '0.058125000',
            'estimated_user_cost_max' => '0.086262500',
        ]);

        // Another user cannot sync someone else's generation
        $this->actingAs($otherUser)
            ->postJson("/generations/{$generation->id}/sync")
            ->assertStatus(403);

        // Owner syncs once -> transitions and deducts
        $this->actingAs($user)
            ->postJson("/generations/{$generation->id}/sync")
            ->assertOk()
            ->assertJsonPath('generation.status', 'succeeded')
            ->assertJsonPath('credit_balance', '9.941850000');

        // Second sync is a no-op because status is already terminal
        $this->actingAs($user)
            ->postJson("/generations/{$generation->id}/sync")
            ->assertOk()
            ->assertJsonPath('credit_balance', '9.941850000');
    }

    public function test_user_can_topup_credits_with_bcmath_precision(): void
    {
        $user = User::factory()->create(['credit_balance' => '1.234567891']);

        $this->actingAs($user)
            ->postJson('/credits/topup', ['amount' => '10.00'])
            ->assertOk()
            ->assertJsonPath('credit_balance', '11.234567891');

        $this->actingAs($user)
            ->postJson('/credits/topup', ['amount' => '99.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }
}
