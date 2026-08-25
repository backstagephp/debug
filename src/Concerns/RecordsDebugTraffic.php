<?php

namespace Backstage\Debug\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared behaviour of the debug logs: they never let a failure of their own
 * reach the code they observe, they never store a credential, they never store
 * a body large enough to be a problem on its own, and they all age out after
 * the same number of days.
 *
 * @phpstan-require-extends Model
 */
trait RecordsDebugTraffic
{
    public const SOURCE_HTTP = 'http';

    public const SOURCE_CONSOLE = 'console';

    /**
     * Whether a write is already in flight. A debug log that fails while it is
     * recording a failure would otherwise call itself, so the second write is
     * dropped instead.
     */
    protected static bool $isRecording = false;

    /**
     * Write a row without letting the recording break what it is recording. A
     * debug log is only ever a help, so an unreachable or unmigrated database
     * leaves the request it was watching untouched.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function write(array $attributes): ?static
    {
        if (static::$isRecording) {
            return null;
        }

        static::$isRecording = true;

        try {
            return static::query()->create($attributes);
        } catch (Throwable $exception) {
            Log::warning(sprintf(
                'Could not record %s: %s',
                class_basename(static::class),
                $exception->getMessage(),
            ));

            return null;
        } finally {
            static::$isRecording = false;
        }
    }

    /**
     * Flatten a header bag to a readable array, replacing the values that
     * carry a credential.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    protected static function redactHeaders(array $headers): array
    {
        $redacted = array_map(strtolower(...), (array) config('debug.redacted_headers', []));

        $flattened = [];

        foreach ($headers as $name => $value) {
            $flattened[$name] = in_array(strtolower((string) $name), $redacted, true)
                ? '[redacted]'
                : implode(', ', array_map(strval(...), Arr::wrap($value)));
        }

        return $flattened;
    }

    /**
     * Keep a body readable without keeping all of it. The full length is
     * recorded separately, so a truncated body still reads as the body that
     * was actually sent or received.
     */
    protected static function truncate(?string $body, ?int $limit = null): ?string
    {
        if (blank($body)) {
            return null;
        }

        return Str::limit((string) $body, $limit ?? static::bodyLimit(), ' […truncated]');
    }

    protected static function bodyLimit(): int
    {
        return (int) config('debug.max_body_length');
    }

    /**
     * Whether the code being observed is serving an HTTP request. Under Octane
     * the process is still a CLI process while it serves requests, so
     * `runningInConsole()` says nothing here: a request that came in over HTTP
     * is the one that carries a request method.
     */
    protected static function isServingHttpRequest(): bool
    {
        return app()->bound('request')
            && filled(request()->server('REQUEST_METHOD'));
    }

    /**
     * The artisan command line the row was recorded from.
     */
    protected static function currentCommand(): ?string
    {
        $arguments = $_SERVER['argv'] ?? null;

        if (! is_array($arguments) || $arguments === []) {
            return null;
        }

        return implode(' ', array_map(strval(...), $arguments));
    }

    /**
     * How many days of history the debug logs keep. Each log's `prunable()`
     * drops everything past this window: a debug log answers "what is going
     * wrong now", so it is worth nothing once it is old enough that nobody is
     * looking for it any more.
     */
    protected static function retentionCutoff(): CarbonInterface
    {
        return now()->subDays((int) config('debug.retention_days'));
    }
}
