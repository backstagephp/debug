<?php

namespace Backstage\Debug\Database\Factories;

use Backstage\Debug\Models\Exception;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<Exception>
 */
class ExceptionFactory extends Factory
{
    protected $model = Exception::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $file = 'app/Services/'.fake()->word().'Service.php';
        $line = fake()->numberBetween(10, 400);

        return [
            'fingerprint' => md5(RuntimeException::class."|{$file}|{$line}"),
            'exception_class' => RuntimeException::class,
            'message' => fake()->sentence(),
            'code' => null,
            'file' => $file,
            'line' => $line,
            'trace' => "#0 {$file}({$line}): ".fake()->word()."()\n#1 {main}",
            'source' => Exception::SOURCE_HTTP,
            'method' => 'GET',
            'url' => fake()->url(),
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'command' => null,
            'user_id' => null,
            'context' => null,
            'occurred_at' => now(),
        ];
    }

    public function fromConsole(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => Exception::SOURCE_CONSOLE,
            'method' => null,
            'url' => null,
            'ip' => null,
            'user_agent' => null,
            'command' => 'artisan schedule:run',
        ]);
    }
}
