<?php

namespace Backstage\Debug\Database\Factories;

use Backstage\Debug\Models\IncomingWebhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncomingWebhook>
 */
class IncomingWebhookFactory extends Factory
{
    protected $model = IncomingWebhook::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $source = fake()->randomElement(['rinkel', 'productive', 'mails']);
        $payload = json_encode(['id' => fake()->uuid()]);
        $responseBody = json_encode(['status' => 'ok']);

        return [
            'source' => $source,
            'method' => 'POST',
            'path' => "/webhooks/{$source}",
            'url' => rtrim((string) config('app.url'), '/')."/webhooks/{$source}",
            'ip' => fake()->ipv4(),
            'headers' => ['content-type' => 'application/json'],
            'query' => null,
            'payload' => $payload,
            'body_size' => strlen((string) $payload),
            'status' => 200,
            'response_body' => $responseBody,
            'response_size' => strlen((string) $responseBody),
            'duration_ms' => fake()->numberBetween(5, 300),
            'received_at' => now(),
        ];
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 401,
            'response_body' => 'Unauthorized',
            'response_size' => 12,
        ]);
    }
}
