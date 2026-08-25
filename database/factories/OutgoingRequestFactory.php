<?php

namespace Backstage\Debug\Database\Factories;

use Backstage\Debug\Models\OutgoingRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutgoingRequest>
 */
class OutgoingRequestFactory extends Factory
{
    protected $model = OutgoingRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $host = fake()->domainName();
        $path = '/'.fake()->slug();
        $responseBody = json_encode(['data' => fake()->words()]);

        return [
            'method' => 'GET',
            'url' => "https://{$host}{$path}",
            'host' => $host,
            'path' => $path,
            'request_headers' => ['accept' => 'application/json'],
            'request_body' => null,
            'request_size' => 0,
            'status' => 200,
            'response_headers' => ['content-type' => 'application/json'],
            'response_body' => $responseBody,
            'response_size' => strlen((string) $responseBody),
            'duration_ms' => fake()->numberBetween(20, 900),
            'error' => null,
            'occurred_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => null,
            'response_headers' => null,
            'response_body' => null,
            'response_size' => 0,
            'duration_ms' => null,
            'error' => 'cURL error 28: Operation timed out',
        ]);
    }

    public function serverError(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 500,
            'response_body' => 'Internal Server Error',
            'response_size' => 21,
        ]);
    }
}
