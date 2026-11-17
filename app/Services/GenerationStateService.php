<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class GenerationStateService
{
    private const STATUS_LEVELS = [
        Generation::STATUS_PENDING => 0,
        Generation::STATUS_QUEUED => 1,
        Generation::STATUS_PROCESSING => 2,
        Generation::STATUS_SUCCEEDED => 3,
        Generation::STATUS_FAILED => 3,
    ];

    private const TERMINAL_STATUSES = [
        Generation::STATUS_SUCCEEDED,
        Generation::STATUS_FAILED,
    ];

    public function __construct(
        private readonly PricingService $pricingService,
    ) {}

    /**
     * Apply an incoming Pixelforge webhook event idempotently with row-level locking.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function applyEvent(array $eventData): ?Generation
    {
        $eventId = $this->extractNonEmptyString($eventData['id'] ?? $eventData['event_id'] ?? null);
        $eventType = $this->extractNonEmptyString($eventData['type'] ?? $eventData['event_type'] ?? null);
        $rawData = is_array($eventData['data'] ?? null) ? $eventData['data'] : $eventData;
        $data = is_array($rawData['object'] ?? null) ? $rawData['object'] : $rawData;

        $pixelforgeId = $this->extractNonEmptyString($data['id'] ?? null);
        $localGenerationId = $this->extractNonEmptyString($data['metadata']['local_generation_id'] ?? null);

        return DB::transaction(function () use (
            $eventData,
            $eventId,
            $eventType,
            $data,
            $pixelforgeId,
            $localGenerationId
        ): ?Generation {
            if ($eventId !== null) {
                if (WebhookEvent::where('event_id', $eventId)->lockForUpdate()->exists()) {
                    return null;
                }

                try {
                    WebhookEvent::create([
                        'event_id' => $eventId,
                        'event_type' => $eventType ?? 'unknown',
                        'pixelforge_generation_id' => $pixelforgeId ?? ($localGenerationId ?? ''),
                        'payload' => $eventData,
                        'processed_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    return null;
                }
            }

            if ($eventType === 'ping') {
                return null;
            }

            $generation = $this->findAndLockGeneration($pixelforgeId, $localGenerationId);
            if (! $generation) {
                return null;
            }

            return $this->transitionGeneration($generation, $data, $eventType);
        });
    }

    /**
     * Apply a generation payload returned directly by Pixelforge REST API (create or polling sync).
     *
     * @param  array<string, mixed>  $apiData
     */
    public function applyApiSnapshot(Generation $generation, array $apiData): Generation
    {
        $rawData = is_array($apiData['data'] ?? null) ? $apiData['data'] : $apiData;
        $data = is_array($rawData['object'] ?? null) ? $rawData['object'] : $rawData;

        return DB::transaction(function () use ($generation, $data): Generation {
            $locked = Generation::where('id', $generation->id)->lockForUpdate()->firstOrFail();

            return $this->transitionGeneration($locked, $data);
        });
    }

    /**
     * Execute strict state transition and billing settlement inside an active DB transaction.
     *
     * @param  array<string, mixed>  $data
     */
    private function transitionGeneration(
        Generation $generation,
        array $data,
        ?string $eventType = null
    ): Generation {
        $pixelforgeId = $this->extractNonEmptyString($data['id'] ?? null);
        if ($pixelforgeId !== null && $generation->pixelforge_id === null) {
            $generation->pixelforge_id = $pixelforgeId;
        }

        $targetStatus = $this->resolveTargetStatus($data, $eventType);
        if ($targetStatus === null) {
            if ($generation->isDirty()) {
                $generation->save();
            }

            return $generation;
        }

        $currentStatus = $generation->status;

        if (in_array($currentStatus, self::TERMINAL_STATUSES, true)) {
            if ($generation->isDirty()) {
                $generation->save();
            }

            return $generation;
        }

        $currentLevel = self::STATUS_LEVELS[$currentStatus] ?? -1;
        $targetLevel = self::STATUS_LEVELS[$targetStatus] ?? -1;

        if ($targetLevel <= $currentLevel) {
            if ($generation->isDirty()) {
                $generation->save();
            }

            return $generation;
        }

        if ($targetStatus === Generation::STATUS_SUCCEEDED) {
            $this->settleSucceeded($generation, $data);

            return $generation;
        }

        if ($targetStatus === Generation::STATUS_FAILED) {
            $this->settleFailed($generation, $data);

            return $generation;
        }

        $generation->status = $targetStatus;
        $generation->save();

        return $generation;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function settleSucceeded(Generation $generation, array $data): void
    {
        bcscale(PricingService::SCALE);

        $usage = $this->extractUsage($data);
        $snapshotRates = is_array($data['rates'] ?? null) && $data['rates'] !== []
            ? $data['rates']
            : ($generation->pricing_snapshot ?? []);

        $costs = $this->pricingService->calculateActualCost($usage, $snapshotRates);

        $output = is_array($data['output'] ?? null) ? $data['output'] : [];
        $imageUrl = $this->extractNonEmptyString($data['image_url'] ?? $output['url'] ?? null);
        $expiresAtRaw = $this->extractNonEmptyString(
            $data['image_url_expires_at']
            ?? $data['image_expires_at']
            ?? $output['expires_at']
            ?? null
        );

        $generation->status = Generation::STATUS_SUCCEEDED;
        $generation->actual_provider_cost = $costs['actual_provider_cost'];
        $generation->actual_user_cost = $costs['actual_user_cost'];
        $generation->token_usage = $usage;
        $generation->image_url = $imageUrl;
        $generation->image_expires_at = $expiresAtRaw !== null ? Carbon::parse($expiresAtRaw) : null;
        $generation->save();

        $user = User::where('id', $generation->user_id)->lockForUpdate()->firstOrFail();
        $user->credit_balance = bcsub(
            (string) $user->credit_balance,
            $costs['actual_user_cost'],
            PricingService::SCALE
        );
        $user->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function settleFailed(Generation $generation, array $data): void
    {
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];

        $generation->status = Generation::STATUS_FAILED;
        $generation->actual_provider_cost = '0.000000000';
        $generation->actual_user_cost = '0.000000000';
        $generation->error_code = $this->extractNonEmptyString($error['code'] ?? $data['error_code'] ?? null);
        $generation->error_message = $this->extractNonEmptyString($error['message'] ?? $data['error_message'] ?? null);
        $generation->save();
    }

    private function findAndLockGeneration(?string $pixelforgeId, ?string $localGenerationId): ?Generation
    {
        if ($pixelforgeId !== null) {
            $generation = Generation::where('pixelforge_id', $pixelforgeId)->lockForUpdate()->first();
            if ($generation !== null) {
                return $generation;
            }
        }

        if ($localGenerationId !== null) {
            return Generation::where('id', $localGenerationId)->lockForUpdate()->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveTargetStatus(array $data, ?string $eventType): ?string
    {
        $status = $this->extractNonEmptyString($data['status'] ?? null);
        if ($status !== null && array_key_exists($status, self::STATUS_LEVELS)) {
            return $status;
        }

        if ($eventType === null) {
            return null;
        }

        return match ($eventType) {
            'generation.queued' => Generation::STATUS_QUEUED,
            'generation.processing' => Generation::STATUS_PROCESSING,
            'generation.succeeded' => Generation::STATUS_SUCCEEDED,
            'generation.failed' => Generation::STATUS_FAILED,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractUsage(array $data): array
    {
        if (is_array($data['usage'] ?? null)) {
            return $data['usage'];
        }

        if (is_array($data['token_usage'] ?? null)) {
            return $data['token_usage'];
        }

        return [];
    }

    private function extractNonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
