<?php

namespace Backstage\Debug\Models;

use Backstage\Debug\Concerns\RecordsDebugTraffic;
use Backstage\Debug\Database\Factories\ExceptionFactory;
use Backstage\Debug\Debug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Every exception the application reports, kept so a failure can be read back
 * long after the request that caused it has gone.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $exception_class
 * @property string $message
 * @property string|null $code
 * @property string|null $file
 * @property int|null $line
 * @property string|null $trace
 * @property string $source
 * @property string|null $method
 * @property string|null $url
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $command
 * @property int|string|null $user_id
 * @property array<string, mixed>|null $context
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Exception extends Model
{
    /** @use HasFactory<ExceptionFactory> */
    use HasFactory;

    use MassPrunable;
    use RecordsDebugTraffic;

    protected $table = 'exceptions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'fingerprint',
        'exception_class',
        'message',
        'code',
        'file',
        'line',
        'trace',
        'source',
        'method',
        'url',
        'ip',
        'user_agent',
        'command',
        'user_id',
        'context',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Record a reported exception together with whatever the application was
     * doing at the time, so the row on its own explains what broke and where.
     *
     * The stack trace is stored trimmed: whitespace around it says nothing
     * about the failure and only gets in the way of reading the first frame or
     * pasting the trace somewhere else.
     *
     * @param  array<string, mixed>  $context
     */
    public static function record(Throwable $exception, array $context = []): ?self
    {
        $isHttpRequest = static::isServingHttpRequest();
        $request = $isHttpRequest ? request() : null;

        $code = (string) $exception->getCode();

        return static::write([
            'fingerprint' => static::fingerprint($exception),
            'exception_class' => $exception::class,
            'message' => (string) $exception->getMessage(),
            'code' => in_array($code, ['', '0'], true) ? null : $code,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => static::truncate(trim($exception->getTraceAsString()), (int) config('debug.max_trace_length')),
            'source' => $isHttpRequest ? self::SOURCE_HTTP : self::SOURCE_CONSOLE,
            'method' => $isHttpRequest ? $request->method() : null,
            'url' => $isHttpRequest ? $request->fullUrl() : null,
            'ip' => $isHttpRequest ? $request->ip() : null,
            'user_agent' => $isHttpRequest ? $request->userAgent() : null,
            'command' => $isHttpRequest ? null : static::currentCommand(),
            'user_id' => $isHttpRequest ? Auth::id() : null,
            'context' => $context === [] ? null : $context,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Debug::userModel());
    }

    /**
     * The class name without its namespace, which is what makes a table of
     * exceptions scannable.
     */
    public function shortClass(): string
    {
        return class_basename($this->exception_class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('occurred_at', '<', static::retentionCutoff());
    }

    /**
     * The same failure keeps the same fingerprint however often it happens,
     * which is what lets the table be narrowed to a single problem. The message
     * is deliberately left out: it usually carries an id or a url that differs
     * between two occurrences of one bug.
     */
    protected static function fingerprint(Throwable $exception): string
    {
        return md5(implode('|', [
            $exception::class,
            $exception->getFile(),
            (string) $exception->getLine(),
        ]));
    }

    protected static function newFactory(): Factory
    {
        return ExceptionFactory::new();
    }
}
