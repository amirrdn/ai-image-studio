<?php

namespace App\Http\Controllers;

use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use App\Services\PixelforgeClient;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AdminReportController extends Controller
{
    public function __construct(
        private readonly PixelforgeClient $pixelforgeClient,
    ) {}

    /**
     * Display aggregated financial & usage report (local BCMath totals vs Pixelforge /v1/usage).
     */
    public function index(Request $request): InertiaResponse|JsonResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        bcscale(PricingService::SCALE);

        $generations = Generation::query()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get([
                'id',
                'user_id',
                'status',
                'actual_user_cost',
                'actual_provider_cost',
                'created_at',
            ]);

        $totalUserCost = '0.000000000';
        $totalProviderCost = '0.000000000';
        $succeededCount = 0;
        $failedCount = 0;

        /** @var array<string, array<string, mixed>> $dailyBuckets */
        $dailyBuckets = [];

        /** @var array<int, array<string, mixed>> $userBuckets */
        $userBuckets = [];

        foreach ($generations as $generation) {
            $userCost = (string) ($generation->actual_user_cost ?? '0.000000000');
            $providerCost = (string) ($generation->actual_provider_cost ?? '0.000000000');
            $isSucceeded = $generation->status === Generation::STATUS_SUCCEEDED;
            $isFailed = $generation->status === Generation::STATUS_FAILED;

            if ($isSucceeded) {
                $succeededCount++;
            } elseif ($isFailed) {
                $failedCount++;
            }

            $totalUserCost = bcadd($totalUserCost, $userCost, PricingService::SCALE);
            $totalProviderCost = bcadd($totalProviderCost, $providerCost, PricingService::SCALE);

            $date = $generation->created_at?->toDateString() ?? 'unknown';
            if (! isset($dailyBuckets[$date])) {
                $dailyBuckets[$date] = [
                    'date' => $date,
                    'generations_count' => 0,
                    'succeeded_count' => 0,
                    'failed_count' => 0,
                    'total_user_cost' => '0.000000000',
                    'total_provider_cost' => '0.000000000',
                    'profit' => '0.000000000',
                ];
            }

            $dailyBuckets[$date]['generations_count']++;
            if ($isSucceeded) {
                $dailyBuckets[$date]['succeeded_count']++;
            } elseif ($isFailed) {
                $dailyBuckets[$date]['failed_count']++;
            }
            $dailyBuckets[$date]['total_user_cost'] = bcadd(
                $dailyBuckets[$date]['total_user_cost'],
                $userCost,
                PricingService::SCALE
            );
            $dailyBuckets[$date]['total_provider_cost'] = bcadd(
                $dailyBuckets[$date]['total_provider_cost'],
                $providerCost,
                PricingService::SCALE
            );

            $userId = (int) $generation->user_id;
            if (! isset($userBuckets[$userId])) {
                $userBuckets[$userId] = [
                    'user_id' => $userId,
                    'name' => $generation->user?->name ?? 'Unknown',
                    'email' => $generation->user?->email ?? '',
                    'generations_count' => 0,
                    'succeeded_count' => 0,
                    'failed_count' => 0,
                    'total_user_cost' => '0.000000000',
                    'total_provider_cost' => '0.000000000',
                    'profit' => '0.000000000',
                ];
            }

            $userBuckets[$userId]['generations_count']++;
            if ($isSucceeded) {
                $userBuckets[$userId]['succeeded_count']++;
            } elseif ($isFailed) {
                $userBuckets[$userId]['failed_count']++;
            }
            $userBuckets[$userId]['total_user_cost'] = bcadd(
                $userBuckets[$userId]['total_user_cost'],
                $userCost,
                PricingService::SCALE
            );
            $userBuckets[$userId]['total_provider_cost'] = bcadd(
                $userBuckets[$userId]['total_provider_cost'],
                $providerCost,
                PricingService::SCALE
            );
        }

        foreach ($dailyBuckets as &$day) {
            $day['profit'] = bcsub($day['total_user_cost'], $day['total_provider_cost'], PricingService::SCALE);
        }
        unset($day);

        foreach ($userBuckets as &$userRow) {
            $userRow['profit'] = bcsub($userRow['total_user_cost'], $userRow['total_provider_cost'], PricingService::SCALE);
        }
        unset($userRow);

        $usageParams = array_filter([
            'start_date' => $request->query('start_date', $request->query('from')),
            'end_date' => $request->query('end_date', $request->query('to')),
        ]);

        $pixelforgeUsage = null;
        $pixelforgeUsageError = null;

        try {
            $pixelforgeUsage = $this->pixelforgeClient->getUsage($usageParams);
        } catch (PixelforgeApiException $e) {
            $pixelforgeUsageError = $e->getMessage();
        }

        $report = [
            'summary' => [
                'total_generations' => $generations->count(),
                'succeeded_generations' => $succeededCount,
                'failed_generations' => $failedCount,
                'total_user_cost' => $totalUserCost,
                'total_provider_cost' => $totalProviderCost,
                'total_profit' => bcsub($totalUserCost, $totalProviderCost, PricingService::SCALE),
            ],
            'daily' => array_values($dailyBuckets),
            'by_user' => array_values($userBuckets),
            'pixelforge_usage' => $pixelforgeUsage,
            'pixelforge_usage_error' => $pixelforgeUsageError,
        ];

        if ($request->wantsJson() && ! $request->inertia()) {
            return response()->json($report);
        }

        return Inertia::render('Admin/Report', $report);
    }
}
