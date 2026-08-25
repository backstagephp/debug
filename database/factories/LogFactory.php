<?php

namespace Backstage\Debug\Database\Factories;

use Backstage\Debug\Models\Log;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Log>
 */
class LogFactory extends Factory
{
    protected $model = Log::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'level' => 'info',
            'severity' => Log::SEVERITIES['info'],
            'message' => fake()->sentence(),
            'context' => null,
            'source' => Log::SOURCE_HTTP,
            'method' => 'GET',
            'url' => fake()->url(),
            'command' => null,
            'user_id' => null,
            'logged_at' => now(),
        ];
    }

    public function level(string $level): static
    {
        return $this->state(fn (array $attributes): array => [
            'level' => $level,
            'severity' => Log::SEVERITIES[$level],
        ]);
    }

    public function fromConsole(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => Log::SOURCE_CONSOLE,
            'method' => null,
            'url' => null,
            'command' => 'artisan schedule:run',
        ]);
    }
}
