<?php

namespace App\Http\Controllers;

use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationStateService;
use App\Services\PixelforgeClient;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class GenerationController extends Controller
{
    private const ALLOWED_TOPUP_AMOUNTS = [
        '5.00',
        '10.00',
        '25.00',
    ];

    public function __construct(
        private readonly PricingService $pricingService,
        private readonly PixelforgeClient $pixelforgeClient,
        private readonly GenerationStateService $stateService,
    ) {}

    /**
     * Render the main Inertia Studio/Dashboard page with user generations, balance, and pricing.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'generations' => $user->generations()->latest()->get(),
            'creditBalance' => $user->credit_balance,
            'pricing' => $this->pricingService->getPricing(),
            'options' => [
                'sizes' => Generation::SIZES,
                'qualities' => Generation::QUALITIES,
                'topup_amounts' => self::ALLOWED_TOPUP_AMOUNTS,
            ],
        ]);
    }

    /**
     * Return real-time token and cost estimation in JSON format.
     */
    public function estimate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['required', 'string', Rule::in(Generation::SIZES)],
            'quality' => ['required', 'string', Rule::in(Generation::QUALITIES)],
            'reference_urls' => ['nullable', 'array', 'max:4'],
            'reference_urls.*' => ['required', 'url', 'max:2048'],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['required', 'url', 'max:2048'],
        ]);

        $referenceUrls = $validated['reference_images'] ?? $validated['reference_urls'] ?? [];

        $estimate = $this->pricingService->estimateCost(
            prompt: (string) ($validated['prompt'] ?? ''),
            size: $validated['size'],
            quality: $validated['quality'],
            referenceUrls: $referenceUrls,
        );

        return response()->json($estimate);
    }

    /**
     * Validate balance, persist pending generation locally, and submit to Pixelforge API.
     *
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'string', Rule::in(Generation::SIZES)],
            'quality' => ['required', 'string', Rule::in(Generation::QUALITIES)],
            'reference_urls' => ['nullable', 'array', 'max:4'],
            'reference_urls.*' => ['required', 'url', 'max:2048'],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['required', 'url', 'max:2048'],
        ]);

        $referenceUrls = $validated['reference_images'] ?? $validated['reference_urls'] ?? [];
        $estimate = $this->pricingService->estimateCost(
            prompt: $validated['prompt'],
            size: $validated['size'],
            quality: $validated['quality'],
            referenceUrls: $referenceUrls,
        );

        bcscale(PricingService::SCALE);
        $user = $request->user();

        if (bccomp((string) $user->credit_balance, $estimate['estimated_user_cost_max'], PricingService::SCALE) < 0) {
            throw ValidationException::withMessages([
                'credit_balance' => sprintf(
                    'Insufficient credit balance (available $%s, maximum estimate $%s). Please buy credits first.',
                    (string) $user->credit_balance,
                    $estimate['estimated_user_cost_max']
                ),
            ]);
        }

        $generation = Generation::create([
            'user_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => $validated['prompt'],
            'size' => $validated['size'],
            'quality' => $validated['quality'],
            'reference_urls' => $referenceUrls !== [] ? array_values($referenceUrls) : null,
            'status' => Generation::STATUS_PENDING,
            'pricing_snapshot' => $estimate['pricing_snapshot'],
            'estimated_provider_cost_min' => $estimate['estimated_provider_cost_min'],
            'estimated_provider_cost_max' => $estimate['estimated_provider_cost_max'],
            'estimated_user_cost_min' => $estimate['estimated_user_cost_min'],
            'estimated_user_cost_max' => $estimate['estimated_user_cost_max'],
        ]);

        try {
            $apiResponse = $this->pixelforgeClient->createGeneration($generation);
            $generation = $this->stateService->applyApiSnapshot($generation, $apiResponse);
        } catch (PixelforgeApiException $e) {
            $generation = $this->stateService->applyApiSnapshot($generation, [
                'status' => Generation::STATUS_FAILED,
                'error' => [
                    'code' => $e->errorCode ?? 'api_error',
                    'message' => $e->getMessage(),
                ],
            ]);

            $friendlyMessage = $this->formatFriendlyError($e);

            if ($this->isPureJsonRequest($request)) {
                $status = $e->statusCode >= 400 && $e->statusCode < 600 ? $e->statusCode : 502;

                return response()->json([
                    'message' => $friendlyMessage,
                    'error_code' => $e->errorCode,
                    'generation' => $generation,
                ], $status);
            }

            return back()->withErrors(['generation' => $friendlyMessage]);
        }

        if ($this->isPureJsonRequest($request)) {
            return response()->json([
                'generation' => $generation,
            ], 201);
        }

        return back()->with('status', 'generation-submitted');
    }

    /**
     * Poll Pixelforge API for the latest generation state as a fallback to webhooks.
     */
    public function sync(Request $request, Generation $generation): JsonResponse|RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 403);

        $isTerminal = in_array($generation->status, [
            Generation::STATUS_SUCCEEDED,
            Generation::STATUS_FAILED,
        ], true);

        if (! $isTerminal && filled($generation->pixelforge_id)) {
            try {
                $apiData = $this->pixelforgeClient->getGeneration((string) $generation->pixelforge_id);
                $generation = $this->stateService->applyApiSnapshot($generation, $apiData);
            } catch (PixelforgeApiException $e) {
                $friendlyMessage = $this->formatFriendlyError($e);

                if ($this->isPureJsonRequest($request)) {
                    $status = $e->statusCode >= 400 && $e->statusCode < 600 ? $e->statusCode : 502;

                    return response()->json([
                        'message' => $friendlyMessage,
                        'generation' => $generation,
                    ], $status);
                }

                return back()->withErrors(['generation' => $friendlyMessage]);
            }
        }

        if ($this->isPureJsonRequest($request)) {
            return response()->json([
                'generation' => $generation->fresh(),
                'credit_balance' => $request->user()->fresh()->credit_balance,
            ]);
        }

        return back();
    }

    /**
     * Simulate adding credits to the authenticated user's balance.
     */
    public function addCredits(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', function (string $attribute, mixed $value, callable $fail): void {
                $normalized = bcadd((string) $value, '0', 2);
                if (! in_array($normalized, self::ALLOWED_TOPUP_AMOUNTS, true)) {
                    $fail('Top-up amount must be one of: $5.00, $10.00, or $25.00.');
                }
            }],
        ]);

        bcscale(PricingService::SCALE);
        $topupAmount = bcadd((string) $validated['amount'], '0', PricingService::SCALE);

        $updatedBalance = DB::transaction(function () use ($request, $topupAmount): string {
            $user = User::where('id', $request->user()->id)->lockForUpdate()->firstOrFail();
            $user->credit_balance = bcadd((string) $user->credit_balance, $topupAmount, PricingService::SCALE);
            $user->save();

            return $user->credit_balance;
        });

        if ($this->isPureJsonRequest($request)) {
            return response()->json([
                'credit_balance' => $updatedBalance,
                'added' => $topupAmount,
            ]);
        }

        return back()->with('status', 'credits-added');
    }

    private function formatFriendlyError(PixelforgeApiException $e): string
    {
        if ($e->statusCode === 400) {
            return 'Prompt was rejected by Pixelforge content policy (no credits were charged).';
        }

        if ($e->isRateOrConcurrencyLimited()) {
            return 'Pixelforge rate or concurrency limit reached. Please wait a moment and try again.';
        }

        if ($e->isOverloaded()) {
            return 'Pixelforge service is currently overloaded. Please try again shortly.';
        }

        if ($e->isValidationError()) {
            return 'Request rejected by Pixelforge: '.$e->getMessage();
        }

        return 'Failed to process request with Pixelforge: '.$e->getMessage();
    }

    private function isPureJsonRequest(Request $request): bool
    {
        return $request->wantsJson() && ! $request->inertia();
    }
}
