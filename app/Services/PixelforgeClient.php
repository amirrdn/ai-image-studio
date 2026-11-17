<?php

namespace App\Services;

use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    /**
     * Submit a new generation job to Pixelforge with an idempotency key.
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    public function createGeneration(Generation $generation): array
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'metadata' => [
                'local_generation_id' => (string) $generation->id,
            ],
        ];

        if (! empty($generation->reference_urls)) {
            $payload['reference_images'] = array_values($generation->reference_urls);
        }

        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders([
                'Idempotency-Key' => (string) $generation->idempotency_key,
            ])
            ->post('/v1/generations', $payload)
        );
    }

    /**
     * Retrieve a generation status and payload from Pixelforge for polling fallback.
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    public function getGeneration(string $pixelforgeId): array
    {
        return $this->send(
            fn (PendingRequest $http) => $http->get("/v1/generations/{$pixelforgeId}")
        );
    }

    /**
     * Retrieve usage report data from Pixelforge for admin analytics.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    public function getUsage(array $params = []): array
    {
        return $this->send(
            fn (PendingRequest $http) => $http->get('/v1/usage', $params)
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    private function send(callable $callback): array
    {
        try {
            $response = $callback($this->client());
        } catch (ConnectionException $e) {
            throw new PixelforgeApiException(
                message: "Unable to connect to Pixelforge API: {$e->getMessage()}",
                statusCode: 0,
                previous: $e,
            );
        }

        if ($response->failed()) {
            throw PixelforgeApiException::fromResponse($response);
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('pixelforge.base_url', ''), '/'))
            ->withToken((string) config('pixelforge.api_key', ''))
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }
}
