<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_test_secret_123456';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.webhook_secret' => $this->secret]);
    }

    public function test_webhook_rejects_missing_expired_and_invalid_hmac_signatures(): void
    {
        $payload = json_encode([
            'id' => 'evt_invalid_test',
            'type' => 'generation.processing',
            'data' => [
                'object' => ['id' => 'gen_none'],
            ],
        ], JSON_THROW_ON_ERROR);

        // 1. Missing header
        $this->postJson('/webhooks/pixelforge', json_decode($payload, true))
            ->assertStatus(401);

        // 2. Expired timestamp (> 300 seconds ago)
        $expiredTs = (string) (time() - 301);
        $expiredSig = hash_hmac('sha256', $expiredTs.'.'.$payload, $this->secret);

        $this->call(
            'POST',
            '/webhooks/pixelforge',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$expiredTs},v1={$expiredSig}",
            ],
            $payload
        )->assertStatus(401);

        // 3. Invalid HMAC signature
        $validTs = (string) time();
        $this->call(
            'POST',
            '/webhooks/pixelforge',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$validTs},v1=deadbeef",
            ],
            $payload
        )->assertStatus(401);
    }

    public function test_webhook_accepts_valid_signature_and_is_idempotent_across_duplicate_events(): void
    {
        $user = User::factory()->create(['credit_balance' => '10.000000000']);
        $generation = $this->createLocalGeneration($user, 'gen_idem_001');

        $eventPayload = [
            'id' => 'evt_idem_001',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'data' => [
                'object' => [
                    'id' => 'gen_idem_001',
                    'object' => 'generation',
                    'status' => 'succeeded',
                    'image_url' => 'https://cdn.pixelforge.test/images/idem.png',
                    'image_url_expires_at' => '2026-11-18T12:00:00Z',
                    'usage' => [
                        'input_text_tokens' => 4,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 200,
                    ],
                ],
            ],
        ];

        // Expected provider cost:
        // text: 4 * 5.00 / 1M = 0.000020000
        // output: 1100 * 40.00 / 1M = 0.044000000
        // reasoning: 200 * 12.50 / 1M = 0.002500000
        // total provider = 0.046520000
        // user (*1.25) = 0.058150000
        // remaining balance = 10.000000000 - 0.058150000 = 9.941850000

        $this->sendSignedWebhook($eventPayload)->assertOk();
        $this->sendSignedWebhook($eventPayload)->assertOk();

        $this->assertSame(1, WebhookEvent::where('event_id', 'evt_idem_001')->count());

        $generation->refresh();
        $user->refresh();

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('0.046520000', $generation->actual_provider_cost);
        $this->assertSame('0.058150000', $generation->actual_user_cost);
        $this->assertSame('https://cdn.pixelforge.test/images/idem.png', $generation->image_url);
        $this->assertNotNull($generation->image_expires_at);
        $this->assertSame('9.941850000', $user->credit_balance);
    }

    public function test_webhook_ignores_out_of_order_events_after_terminal_or_higher_state(): void
    {
        $user = User::factory()->create(['credit_balance' => '10.000000000']);
        $generation = $this->createLocalGeneration($user, 'gen_ooo_001');

        // Succeeded arrives first!
        $this->sendSignedWebhook([
            'id' => 'evt_ooo_succeeded',
            'type' => 'generation.succeeded',
            'data' => [
                'object' => [
                    'id' => 'gen_ooo_001',
                    'status' => 'succeeded',
                    'image_url' => 'https://cdn.pixelforge.test/images/ooo.png',
                    'image_url_expires_at' => '2026-11-18T12:00:00Z',
                    'usage' => [
                        'input_text_tokens' => 4,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 200,
                    ],
                ],
            ],
        ])->assertOk();

        // Late processing & queued events arrive after succeeded
        $this->sendSignedWebhook([
            'id' => 'evt_ooo_processing',
            'type' => 'generation.processing',
            'data' => [
                'object' => [
                    'id' => 'gen_ooo_001',
                    'status' => 'processing',
                ],
            ],
        ])->assertOk();

        $this->sendSignedWebhook([
            'id' => 'evt_ooo_queued',
            'type' => 'generation.queued',
            'data' => [
                'object' => [
                    'id' => 'gen_ooo_001',
                    'status' => 'queued',
                ],
            ],
        ])->assertOk();

        $generation->refresh();
        $user->refresh();

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('9.941850000', $user->credit_balance);
        $this->assertSame(3, WebhookEvent::count());
    }

    public function test_webhook_handles_race_condition_via_local_generation_id_and_populates_pixelforge_id(): void
    {
        $user = User::factory()->create(['credit_balance' => '10.000000000']);
        $generation = $this->createLocalGeneration($user, null);

        $this->sendSignedWebhook([
            'id' => 'evt_race_001',
            'type' => 'generation.succeeded',
            'data' => [
                'object' => [
                    'id' => 'gen_race_999',
                    'status' => 'succeeded',
                    'metadata' => [
                        'local_generation_id' => $generation->id,
                    ],
                    'image_url' => 'https://cdn.pixelforge.test/images/race.png',
                    'usage' => [
                        'input_text_tokens' => 4,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 200,
                    ],
                ],
            ],
        ])->assertOk();

        $generation->refresh();
        $user->refresh();

        $this->assertSame('gen_race_999', $generation->pixelforge_id);
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('9.941850000', $user->credit_balance);
    }

    public function test_failed_generation_webhook_sets_zero_cost_and_does_not_deduct_balance(): void
    {
        $user = User::factory()->create(['credit_balance' => '10.000000000']);
        $generation = $this->createLocalGeneration($user, 'gen_fail_001');

        $this->sendSignedWebhook([
            'id' => 'evt_fail_001',
            'type' => 'generation.failed',
            'data' => [
                'object' => [
                    'id' => 'gen_fail_001',
                    'status' => 'failed',
                    'error' => [
                        'code' => 'generation_failed',
                        'message' => 'Simulated generation failure.',
                    ],
                ],
            ],
        ])->assertOk();

        $generation->refresh();
        $user->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('0.000000000', $generation->actual_provider_cost);
        $this->assertSame('0.000000000', $generation->actual_user_cost);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertSame('Simulated generation failure.', $generation->error_message);
        $this->assertSame('10.000000000', $user->credit_balance);
    }

    private function createLocalGeneration(User $user, ?string $pixelforgeId): Generation
    {
        return Generation::create([
            'user_id' => $user->id,
            'pixelforge_id' => $pixelforgeId,
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => 'A peaceful zen garden',
            'size' => '1024x1024',
            'quality' => 'medium',
            'status' => Generation::STATUS_PENDING,
            'pricing_snapshot' => PricingService::DEFAULT_RATES,
            'estimated_provider_cost_min' => '0.046500000',
            'estimated_provider_cost_max' => '0.069010000',
            'estimated_user_cost_min' => '0.058125000',
            'estimated_user_cost_max' => '0.086262500',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendSignedWebhook(array $payload)
    {
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);

        return $this->call(
            'POST',
            '/webhooks/pixelforge',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $rawBody
        );
    }
}
