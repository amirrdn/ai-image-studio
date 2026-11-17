<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoreModelsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_default_credit_balance_and_is_admin_flag(): void
    {
        $user = User::factory()->create()->fresh();

        $this->assertSame('10.000000000', $user->credit_balance);
        $this->assertFalse($user->is_admin);
    }

    public function test_generation_persists_with_uuid_and_nine_decimal_precision(): void
    {
        $user = User::factory()->create();

        $generation = Generation::create([
            'user_id' => $user->id,
            'pixelforge_id' => 'gen_test_001',
            'prompt' => 'A serene mountain lake',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_urls' => ['https://example.com/ref.jpg'],
            'status' => Generation::STATUS_QUEUED,
            'pricing_snapshot' => PricingService::DEFAULT_RATES,
            'estimated_provider_cost_min' => '0.056930000',
            'estimated_provider_cost_max' => '0.061430000',
            'estimated_user_cost_min' => '0.071162500',
            'estimated_user_cost_max' => '0.076787500',
            'actual_provider_cost' => '0.055397500',
            'actual_user_cost' => '0.069246875',
        ])->fresh();

        $this->assertNotEmpty($generation->id);
        $this->assertNotEmpty($generation->idempotency_key);
        $this->assertSame('0.071162500', $generation->estimated_user_cost_min);
        $this->assertSame('0.069246875', $generation->actual_user_cost);
        $this->assertTrue($generation->user->is($user));
        $this->assertCount(1, $user->generations);
    }

    public function test_webhook_event_persists_and_links_to_generation(): void
    {
        $user = User::factory()->create();

        $generation = Generation::create([
            'user_id' => $user->id,
            'pixelforge_id' => 'gen_webhook_001',
            'prompt' => 'Cyberpunk cat',
            'size' => '1024x1536',
            'quality' => 'high',
            'pricing_snapshot' => PricingService::DEFAULT_RATES,
            'estimated_provider_cost_min' => '0.160802500',
            'estimated_provider_cost_max' => '0.172802500',
            'estimated_user_cost_min' => '0.201003125',
            'estimated_user_cost_max' => '0.216003125',
        ]);

        $event = WebhookEvent::create([
            'event_id' => 'evt_001',
            'event_type' => 'generation.succeeded',
            'pixelforge_generation_id' => 'gen_webhook_001',
            'payload' => ['id' => 'evt_001', 'type' => 'generation.succeeded'],
            'processed_at' => now(),
        ]);

        $this->assertTrue($event->generation->is($generation));
        $this->assertNotNull($event->processed_at);
    }
}

