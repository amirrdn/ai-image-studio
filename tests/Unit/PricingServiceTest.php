<?php

namespace Tests\Unit;

use App\Services\PricingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['pixelforge.base_url' => 'https://pixelforge.test']);
        config(['pixelforge.api_key' => 'pf_test_key']);
    }

    public function test_get_pricing_fetches_and_caches_rates_for_five_minutes(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/pricing' => Http::response([
                'object' => 'pricing',
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'tokens' => [
                    'characters_per_text_token' => 4,
                    'text_token_variance' => 0.15,
                    'per_reference_image' => 765,
                ],
            ], 200),
        ]);

        $service = new PricingService;

        $first = $service->getPricing();
        $second = $service->getPricing();

        $this->assertSame([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ], $first);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_get_pricing_falls_back_to_default_rates_when_api_fails(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/pricing' => Http::response(['error' => 'unavailable'], 503),
        ]);

        $service = new PricingService;
        $rates = $service->getPricing();

        $this->assertSame(PricingService::DEFAULT_RATES, $rates);
    }

    public function test_estimate_cost_calculates_exact_bcmath_values_with_markup(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/pricing' => Http::response([], 500),
        ]);

        $service = new PricingService;

        // "abcde" = 5 chars => base 2 tokens; variance +-15% => min 1, max 3
        // 1 reference URL => 765 input_image_tokens
        // 1024x1024 medium => 1100 output_tokens, reasoning 200..2000
        // Fallback rates: text=5.00, image=10.00, output=40.00, reasoning=12.50
        // provider min = 0.054155000 -> user min = 0.067693750
        // provider max = 0.076665000 -> user max = 0.095831250
        $estimate = $service->estimateCost('abcde', '1024x1024', 'medium', ['https://example.com/ref.png']);

        $this->assertSame(2, $estimate['tokens']['input_text_tokens']);
        $this->assertSame(1, $estimate['tokens']['input_text_tokens_min']);
        $this->assertSame(3, $estimate['tokens']['input_text_tokens_max']);
        $this->assertSame(765, $estimate['tokens']['input_image_tokens']);
        $this->assertSame(1100, $estimate['tokens']['output_tokens']);
        $this->assertSame(200, $estimate['tokens']['reasoning_tokens_min']);
        $this->assertSame(2000, $estimate['tokens']['reasoning_tokens_max']);

        $this->assertSame('0.054155000', $estimate['estimated_provider_cost_min']);
        $this->assertSame('0.076665000', $estimate['estimated_provider_cost_max']);
        $this->assertSame('0.067693750', $estimate['estimated_user_cost_min']);
        $this->assertSame('0.095831250', $estimate['estimated_user_cost_max']);
    }

    public function test_estimate_cost_handles_high_wide_and_multibyte_utf8(): void
    {
        Http::fake([
            'https://pixelforge.test/v1/pricing' => Http::response([], 500),
        ]);

        $service = new PricingService;

        // 4 multibyte UTF-8 characters => mb_strlen = 4 => base 1 (min 0, max 2)
        // 1536x1024 high => output 6450, reasoning 1000..8000
        // provider min = 0.270500000 -> user min = 0.338125000
        // provider max = 0.358010000 -> user max = 0.447512500
        $estimate = $service->estimateCost('éüñç', '1536x1024', 'high');

        $this->assertSame(1, $estimate['tokens']['input_text_tokens']);
        $this->assertSame(6450, $estimate['tokens']['output_tokens']);
        $this->assertSame('0.270500000', $estimate['estimated_provider_cost_min']);
        $this->assertSame('0.358010000', $estimate['estimated_provider_cost_max']);
        $this->assertSame('0.338125000', $estimate['estimated_user_cost_min']);
        $this->assertSame('0.447512500', $estimate['estimated_user_cost_max']);
    }

    public function test_calculate_actual_cost_with_nine_decimal_precision(): void
    {
        $service = new PricingService;

        $actual = $service->calculateActualCost([
            'input_text_tokens' => 1,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 253,
        ], PricingService::DEFAULT_RATES);

        $this->assertSame('0.047167500', $actual['actual_provider_cost']);
        $this->assertSame('0.058959375', $actual['actual_user_cost']);
    }

    public function test_estimate_cost_rejects_invalid_size_or_quality(): void
    {
        $service = new PricingService;

        $this->expectException(InvalidArgumentException::class);
        $service->estimateCost('hello', '512x512', 'medium');
    }
}
