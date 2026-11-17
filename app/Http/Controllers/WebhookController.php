<?php

namespace App\Http\Controllers;

use App\Services\GenerationStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    private const MAX_TIMESTAMP_DRIFT_SECONDS = 300;

    public function __construct(
        private readonly GenerationStateService $stateService,
    ) {}

    /**
     * Handle incoming Pixelforge webhook events with HMAC-SHA256 signature verification.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signatureHeader = $request->header('Pixelforge-Signature');

        if (! $this->hasValidSignature($signatureHeader, $rawBody)) {
            return response()->json([
                'message' => 'Invalid or expired webhook signature.',
            ], 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json([
                'message' => 'Invalid JSON payload.',
            ], 400);
        }

        $this->stateService->applyEvent($payload);

        return response()->json(['received' => true], 200);
    }

    private function hasValidSignature(?string $header, string $rawBody): bool
    {
        if (! is_string($header) || trim($header) === '') {
            return false;
        }

        $secret = (string) config('pixelforge.webhook_secret', '');
        if ($secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $segment) {
            $parts = explode('=', trim($segment), 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$key, $value] = $parts;
            $key = trim($key);
            $value = trim($value);

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_DRIFT_SECONDS) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expectedSignature, $candidate)) {
                return true;
            }
        }

        return false;
    }
}

