<?php

namespace Tests\Unit;

use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use App\Services\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.base_url' => 'https://pixelforge.test']);
        config(['pixelforge.api_key' => 'pf_secret_api_key']);
    }

    public function test_create_generation_sends_expected_headers_and_payload(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/generations' => Http::response([
                'id' => 'gen_abc123',
                'status' => 'queued',
            ], 202),
        ]);

        $generation = new Generation([
            'prompt' => 'A futuristic city at sunset',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_urls' => ['https://example.com/style.png'],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $generation->id = (string) Str::uuid();

        $client = new PixelforgeClient;
        $result = $client->createGeneration($generation);

        $this->assertSame('gen_abc123', $result['id']);

        Http::assertSent(function (Request $request) use ($generation): bool {
            return $request->url() === 'https://pixelforge.test/v1/generations'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer pf_secret_api_key')
                && $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
                && $request['prompt'] === 'A futuristic city at sunset'
                && $request['size'] === '1536x1024'
                && $request['quality'] === 'high'
                && $request['reference_images'] === ['https://example.com/style.png']
                && ! array_key_exists('reference_urls', $request->data())
                && $request['metadata'] === ['local_generation_id' => $generation->id];
        });
    }

    public function test_create_generation_omits_reference_images_when_empty(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/generations' => Http::response(['id' => 'gen_no_ref'], 202),
        ]);

        $generation = new Generation([
            'prompt' => 'Minimalist mountain',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_urls' => null,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $generation->id = (string) Str::uuid();

        $client = new PixelforgeClient;
        $client->createGeneration($generation);

        Http::assertSent(function (Request $request): bool {
            return ! array_key_exists('reference_images', $request->data())
                && ! array_key_exists('reference_urls', $request->data());
        });
    }

    public function test_get_generation_and_get_usage_endpoints(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/generations/gen_123' => Http::response([
                'id' => 'gen_123',
                'status' => 'succeeded',
            ], 200),
            'https://pixelforge.test/v1/usage*' => Http::response([
                'total' => ['cost_usd' => '1.250000000'],
            ], 200),
        ]);

        $client = new PixelforgeClient;

        $gen = $client->getGeneration('gen_123');
        $usage = $client->getUsage(['start_date' => '2026-11-01', 'end_date' => '2026-11-17']);

        $this->assertSame('succeeded', $gen['status']);
        $this->assertSame('1.250000000', $usage['total']['cost_usd']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://pixelforge.test/v1/usage?start_date=2026-11-01&end_date=2026-11-17');
    }

    public function test_handles_429_rate_and_concurrency_limit_exception(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/generations' => Http::response([
                'error' => [
                    'code' => 'concurrency_limit_exceeded',
                    'message' => 'Too many concurrent generations active',
                ],
            ], 429, ['Retry-After' => '15']),
        ]);

        $generation = new Generation([
            'prompt' => 'Test',
            'size' => '1024x1024',
            'quality' => 'medium',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $generation->id = (string) Str::uuid();

        $client = new PixelforgeClient;

        try {
            $client->createGeneration($generation);
            $this->fail('Expected PixelforgeApiException');
        } catch (PixelforgeApiException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertSame('concurrency_limit_exceeded', $e->errorCode);
            $this->assertSame(15, $e->retryAfter);
            $this->assertTrue($e->isRateOrConcurrencyLimited());
            $this->assertTrue($e->isRetryable());
            $this->assertStringContainsString('concurrency limit exceeded', $e->getMessage());
            $this->assertStringContainsString('Retry after 15s', $e->getMessage());
        }
    }

    public function test_handles_503_overloaded_and_422_validation_exceptions(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/generations/gen_503' => Http::response([
                'error' => [
                    'code' => 'overloaded',
                    'message' => 'Upstream GPU cluster saturated',
                ],
            ], 503, ['Retry-After' => '2']),
            'https://pixelforge.test/v1/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'validation_failed',
                    'message' => 'The selected quality is invalid.',
                    'errors' => [
                        'quality' => ['The quality must be one of: low, medium, high.'],
                    ],
                ],
            ], 422),
        ]);

        $client = new PixelforgeClient;

        try {
            $client->getGeneration('gen_503');
            $this->fail('Expected 503 PixelforgeApiException');
        } catch (PixelforgeApiException $e) {
            $this->assertSame(503, $e->statusCode);
            $this->assertTrue($e->isOverloaded());
            $this->assertTrue($e->isRetryable());
            $this->assertStringContainsString('overloaded', $e->getMessage());
        }

        $generation = new Generation([
            'prompt' => 'Test',
            'size' => '1024x1024',
            'quality' => 'medium',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $generation->id = (string) Str::uuid();

        try {
            $client->createGeneration($generation);
            $this->fail('Expected 422 PixelforgeApiException');
        } catch (PixelforgeApiException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertTrue($e->isValidationError());
            $this->assertFalse($e->isRetryable());
            $this->assertStringContainsString('validation failed', $e->getMessage());
        }
    }
}
