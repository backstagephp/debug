<?php

namespace Backstage\Debug\Models;

use Backstage\Debug\Concerns\RecordsDebugTraffic;
use Backstage\Debug\Database\Factories\LogFactory;
use Backstage\Debug\Debug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use JsonSerializable;
use Throwable;

/**
 * Every line the application logs, kept alongside the log files so it can be
 * searched and filtered rather than grepped.
 *
 * The name is shared with the `Log` facade, so a file that needs both has to
 * name one of them differently: `logger()` writes a line without importing the
 * facade at all, which is the way out that reads best.
 *
 * @property int $id
 * @property string $level
 * @property int $severity
 * @property string $message
 * @property string|null $context
 * @property string $source
 * @property string|null $method
 * @property string|null $url
 * @property string|null $command
 * @property int|string|null $user_id
 * @property Carbon $logged_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Log extends Model
{
    /** @use HasFactory<LogFactory> */
    use HasFactory;

    use MassPrunable;
    use RecordsDebugTraffic;

    /**
     * The PSR-3 levels in order of severity. Storing the position alongside the
     * name turns "everything from warning up" into a single comparison.
     *
     * @var array<string, int>
     */
    public const SEVERITIES = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    protected $table = 'logs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'level',
        'severity',
        'message',
        'context',
        'source',
        'method',
        'url',
        'command',
        'user_id',
        'logged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => 'integer',
            'logged_at' => 'datetime',
        ];
    }

    /**
     * Record a logged line together with whatever the application was doing at
     * the time.
     *
     * @param  array<string, mixed>  $context
     */
    public static function record(string $level, string $message, array $context = []): ?self
    {
        $isHttpRequest = static::isServingHttpRequest();

        return static::write([
            'level' => $level,
            'severity' => static::SEVERITIES[strtolower($level)] ?? 0,
            'message' => static::truncate($message) ?? '',
            'context' => static::encodeContext($context),
            'source' => $isHttpRequest ? self::SOURCE_HTTP : self::SOURCE_CONSOLE,
            'method' => $isHttpRequest ? request()->method() : null,
            'url' => $isHttpRequest ? request()->fullUrl() : null,
            'command' => $isHttpRequest ? null : static::currentCommand(),
            'user_id' => Auth::id(),
            'logged_at' => now(),
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
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('logged_at', '<', static::retentionCutoff());
    }

    public function isProblem(): bool
    {
        return $this->severity >= self::SEVERITIES['warning'];
    }

    /**
     * Lay the context out as JSON. Anything that JSON cannot carry — the
     * exception Laravel attaches to a reported error, a model, a closure — is
     * reduced to something readable first, so one awkward value never costs the
     * whole line its context.
     *
     * @param  array<string, mixed>  $context
     */
    protected static function encodeContext(array $context): ?string
    {
        if ($context === []) {
            return null;
        }

        $encoded = json_encode(
            static::readable($context),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return static::truncate($encoded === false ? null : $encoded);
    }

    protected static function readable(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Throwable => sprintf(
                '%s: %s at %s:%d',
                $value::class,
                $value->getMessage(),
                $value->getFile(),
                $value->getLine(),
            ),
            is_array($value) => array_map(static::readable(...), $value),
            is_scalar($value), $value === null, $value instanceof JsonSerializable => $value,
            is_object($value) => method_exists($value, '__toString') ? (string) $value : $value::class,
            default => $value,
        };
    }

    protected static function newFactory(): Factory
    {
        return LogFactory::new();
    }
}
