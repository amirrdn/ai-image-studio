<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class PricingService
{
    public const SCALE = 9;

    public const MARKUP_MULTIPLIER = '1.25';

    public const TOKENS_PER_MILLION = '1000000';

    public const CHARS_PER_TEXT_TOKEN = 4;

    public const TEXT_TOKEN_VARIANCE = 0.15;

    public const TOKENS_PER_REFERENCE_IMAGE = 765;

    public const PRICING_CACHE_KEY = 'pixelforge.pricing_spec';

    public const PRICING_CACHE_TTL_MINUTES = 5;

    /**
     * Fallback rates per 1M tokens (USD) matching GET /v1/pricing.
     */
    public const DEFAULT_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * Output token matrix keyed by quality and image size.
     */
    public const DEFAULT_OUTPUT_TOKENS = [
        'low' => [
            '1024x1024' => 280,
            '1536x1024' => 420,
            '1024x1536' => 420,
        ],
        'medium' => [
            '1024x1024' => 1100,
            '1536x1024' => 1650,
            '1024x1536' => 1650,
        ],
        'high' => [
            '1024x1024' => 4300,
            '1536x1024' => 6450,
            '1024x1536' => 6450,
        ],
    ];

    /**
     * Reasoning token min/max ranges keyed by quality.
     */
    public const DEFAULT_REASONING_TOKENS = [
        'low' => ['min' => 0, 'max' => 500],
        'medium' => ['min' => 200, 'max' => 2000],
        'high' => ['min' => 1000, 'max' => 8000],
    ];

    /**
     * Fetch pricing rates per 1M tokens (cached 5 minutes) with fallback defaults.
     *
     * @return array<string, string>
     */
    public function getPricing(): array
    {
        return $this->getPricingSpecification()['rates'];
    }

    /**
     * Fetch full pricing specification (rates + token tables) from GET /v1/pricing with 5-minute cache.
     *
     * @return array{
     *     rates: array<string, string>,
     *     tokens: array{
     *         characters_per_text_token: int,
     *         text_token_variance: float,
     *         per_reference_image: int,
     *         output: array<string, array<string, int>>,
     *         reasoning: array<string, array{min: int, max: int}>
     *     }
     * }
     */
    public function getPricingSpecification(): array
    {
        $cached = Cache::get(self::PRICING_CACHE_KEY);
        if (is_array($cached) && is_array($cached['rates'] ?? null)) {
            return $this->normalizeSpecification($cached);
        }

        $baseUrl = rtrim((string) config('pixelforge.base_url', ''), '/');
        if ($baseUrl === '') {
            return $this->defaultSpecification();
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->timeout(5);

            $apiKey = (string) config('pixelforge.api_key', '');
            if ($apiKey !== '') {
                $request = $request->withToken($apiKey);
            }

            $response = $request->get('/v1/pricing');

            if ($response->successful() && is_array($response->json('rates'))) {
                $spec = $this->normalizeSpecification($response->json());
                Cache::put(
                    self::PRICING_CACHE_KEY,
                    $spec,
                    now()->addMinutes(self::PRICING_CACHE_TTL_MINUTES)
                );

                return $spec;
            }

            Log::warning('Failed to fetch Pixelforge pricing; using fallback rates.', [
                'status' => $response->status(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Exception while fetching Pixelforge pricing; using fallback rates.', [
                'message' => $e->getMessage(),
            ]);
        }

        return $this->defaultSpecification();
    }

    /**
     * Estimate provider and user cost ranges using BCMath (scale 9).
     *
     * @param  array<int, string>  $referenceUrls
     * @return array<string, mixed>
     */
    public function estimateCost(string $prompt, string $size, string $quality, array $referenceUrls = []): array
    {
        $this->assertValidParameters($size, $quality);

        bcscale(self::SCALE);

        $spec = $this->getPricingSpecification();
        $rates = $spec['rates'];
        $tokensConfig = $spec['tokens'];

        $charsPerToken = max(1, (int) $tokensConfig['characters_per_text_token']);
        $variancePercent = (int) round(((float) $tokensConfig['text_token_variance']) * 100);

        $baseInputTextTokens = (int) ceil(mb_strlen($prompt, 'UTF-8') / $charsPerToken);
        $inputTextTokensMin = $baseInputTextTokens > 0
            ? (int) floor(($baseInputTextTokens * max(0, 100 - $variancePercent)) / 100)
            : 0;
        $inputTextTokensMax = $baseInputTextTokens > 0
            ? (int) ceil(($baseInputTextTokens * (100 + $variancePercent)) / 100)
            : 0;

        $inputImageTokens = ((int) $tokensConfig['per_reference_image']) * count($referenceUrls);
        $outputTokens = (int) $tokensConfig['output'][$quality][$size];
        $reasoningMin = (int) $tokensConfig['reasoning'][$quality]['min'];
        $reasoningMax = (int) $tokensConfig['reasoning'][$quality]['max'];

        $inputTextCostMin = $this->calculateTokenGroupCost($inputTextTokensMin, $rates['input_text_tokens']);
        $inputTextCostMax = $this->calculateTokenGroupCost($inputTextTokensMax, $rates['input_text_tokens']);
        $inputImageCost = $this->calculateTokenGroupCost($inputImageTokens, $rates['input_image_tokens']);
        $outputCost = $this->calculateTokenGroupCost($outputTokens, $rates['output_tokens']);
        $reasoningCostMin = $this->calculateTokenGroupCost($reasoningMin, $rates['reasoning_tokens']);
        $reasoningCostMax = $this->calculateTokenGroupCost($reasoningMax, $rates['reasoning_tokens']);

        $fixedProviderCost = bcadd($inputImageCost, $outputCost, self::SCALE);
        $providerCostMin = bcadd(bcadd($fixedProviderCost, $inputTextCostMin, self::SCALE), $reasoningCostMin, self::SCALE);
        $providerCostMax = bcadd(bcadd($fixedProviderCost, $inputTextCostMax, self::SCALE), $reasoningCostMax, self::SCALE);

        $userCostMin = bcmul($providerCostMin, self::MARKUP_MULTIPLIER, self::SCALE);
        $userCostMax = bcmul($providerCostMax, self::MARKUP_MULTIPLIER, self::SCALE);

        return [
            'pricing_snapshot' => $rates,
            'tokens' => [
                'input_text_tokens' => $baseInputTextTokens,
                'input_text_tokens_min' => $inputTextTokensMin,
                'input_text_tokens_max' => $inputTextTokensMax,
                'input_image_tokens' => $inputImageTokens,
                'output_tokens' => $outputTokens,
                'reasoning_tokens_min' => $reasoningMin,
                'reasoning_tokens_max' => $reasoningMax,
            ],
            'estimated_provider_cost_min' => $providerCostMin,
            'estimated_provider_cost_max' => $providerCostMax,
            'estimated_user_cost_min' => $userCostMin,
            'estimated_user_cost_max' => $userCostMax,
        ];
    }

    /**
     * Calculate exact actual provider and user costs from token usage and a pricing snapshot.
     *
     * @param  array<string, int|string>  $usage
     * @param  array<string, mixed>  $pricingSnapshot
     * @return array{actual_provider_cost: string, actual_user_cost: string}
     */
    public function calculateActualCost(array $usage, array $pricingSnapshot): array
    {
        bcscale(self::SCALE);

        $rawRates = is_array($pricingSnapshot['rates'] ?? null)
            ? $pricingSnapshot['rates']
            : $pricingSnapshot;

        $rates = $this->normalizeRates($rawRates);

        $inputTextTokens = $this->extractTokenCount($usage, ['input_text_tokens', 'input_text']);
        $inputImageTokens = $this->extractTokenCount($usage, ['input_image_tokens', 'input_image']);
        $outputTokens = $this->extractTokenCount($usage, ['output_tokens', 'output']);
        $reasoningTokens = $this->extractTokenCount($usage, ['reasoning_tokens', 'reasoning']);

        $inputTextCost = $this->calculateTokenGroupCost($inputTextTokens, $rates['input_text_tokens']);
        $inputImageCost = $this->calculateTokenGroupCost($inputImageTokens, $rates['input_image_tokens']);
        $outputCost = $this->calculateTokenGroupCost($outputTokens, $rates['output_tokens']);
        $reasoningCost = $this->calculateTokenGroupCost($reasoningTokens, $rates['reasoning_tokens']);

        $actualProviderCost = bcadd(
            bcadd($inputTextCost, $inputImageCost, self::SCALE),
            bcadd($outputCost, $reasoningCost, self::SCALE),
            self::SCALE
        );

        $actualUserCost = bcmul($actualProviderCost, self::MARKUP_MULTIPLIER, self::SCALE);

        return [
            'actual_provider_cost' => $actualProviderCost,
            'actual_user_cost' => $actualUserCost,
        ];
    }

    private function assertValidParameters(string $size, string $quality): void
    {
        if (! in_array($size, Generation::SIZES, true)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported size "%s". Allowed: %s.', $size, implode(', ', Generation::SIZES))
            );
        }

        if (! in_array($quality, Generation::QUALITIES, true)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported quality "%s". Allowed: %s.', $quality, implode(', ', Generation::QUALITIES))
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSpecification(): array
    {
        return [
            'rates' => self::DEFAULT_RATES,
            'tokens' => [
                'characters_per_text_token' => self::CHARS_PER_TEXT_TOKEN,
                'text_token_variance' => self::TEXT_TOKEN_VARIANCE,
                'per_reference_image' => self::TOKENS_PER_REFERENCE_IMAGE,
                'output' => self::DEFAULT_OUTPUT_TOKENS,
                'reasoning' => self::DEFAULT_REASONING_TOKENS,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizeSpecification(array $raw): array
    {
        $defaults = $this->defaultSpecification();
        $rawTokens = is_array($raw['tokens'] ?? null) ? $raw['tokens'] : [];

        $output = $defaults['tokens']['output'];
        if (is_array($rawTokens['output'] ?? null)) {
            foreach (Generation::QUALITIES as $quality) {
                if (is_array($rawTokens['output'][$quality] ?? null)) {
                    foreach (Generation::SIZES as $size) {
                        if (is_numeric($rawTokens['output'][$quality][$size] ?? null)) {
                            $output[$quality][$size] = (int) $rawTokens['output'][$quality][$size];
                        }
                    }
                }
            }
        }

        $reasoning = $defaults['tokens']['reasoning'];
        if (is_array($rawTokens['reasoning'] ?? null)) {
            foreach (Generation::QUALITIES as $quality) {
                $range = $rawTokens['reasoning'][$quality] ?? null;
                if (is_array($range)) {
                    $min = $range['min'] ?? $range[0] ?? null;
                    $max = $range['max'] ?? $range[1] ?? null;
                    if (is_numeric($min) && is_numeric($max)) {
                        $reasoning[$quality] = ['min' => (int) $min, 'max' => (int) $max];
                    }
                }
            }
        }

        return [
            'rates' => $this->normalizeRates(is_array($raw['rates'] ?? null) ? $raw['rates'] : []),
            'tokens' => [
                'characters_per_text_token' => is_numeric($rawTokens['characters_per_text_token'] ?? null)
                    ? (int) $rawTokens['characters_per_text_token']
                    : self::CHARS_PER_TEXT_TOKEN,
                'text_token_variance' => is_numeric($rawTokens['text_token_variance'] ?? null)
                    ? (float) $rawTokens['text_token_variance']
                    : self::TEXT_TOKEN_VARIANCE,
                'per_reference_image' => is_numeric($rawTokens['per_reference_image'] ?? null)
                    ? (int) $rawTokens['per_reference_image']
                    : self::TOKENS_PER_REFERENCE_IMAGE,
                'output' => $output,
                'reasoning' => $reasoning,
            ],
        ];
    }

    /**
     * Normalize rate map into decimal strings keyed by token category.
     *
     * @param  array<string, mixed>  $rates
     * @return array<string, string>
     */
    private function normalizeRates(array $rates): array
    {
        return [
            'input_text_tokens' => $this->resolveRate(
                $rates,
                ['input_text_tokens', 'input_text'],
                self::DEFAULT_RATES['input_text_tokens']
            ),
            'input_image_tokens' => $this->resolveRate(
                $rates,
                ['input_image_tokens', 'input_image'],
                self::DEFAULT_RATES['input_image_tokens']
            ),
            'output_tokens' => $this->resolveRate(
                $rates,
                ['output_tokens', 'output'],
                self::DEFAULT_RATES['output_tokens']
            ),
            'reasoning_tokens' => $this->resolveRate(
                $rates,
                ['reasoning_tokens', 'reasoning'],
                self::DEFAULT_RATES['reasoning_tokens']
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $rates
     * @param  array<int, string>  $keys
     */
    private function resolveRate(array $rates, array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $rates) && is_numeric($rates[$key])) {
                return (string) $rates[$key];
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<int, string>  $keys
     */
    private function extractTokenCount(array $usage, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $usage) && is_numeric($usage[$key])) {
                return bcadd((string) $usage[$key], '0', 0);
            }
        }

        return '0';
    }

    private function calculateTokenGroupCost(int|string $tokenCount, string $ratePerMillion): string
    {
        $tokens = (string) $tokenCount;
        if (bccomp($tokens, '0', 0) <= 0) {
            return '0.000000000';
        }

        $scaled = bcmul($tokens, $ratePerMillion, self::SCALE);

        return bcdiv($scaled, self::TOKENS_PER_MILLION, self::SCALE);
    }
}
