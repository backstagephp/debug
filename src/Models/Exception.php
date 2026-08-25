<?php

namespace Backstage\Debug\Models;

use Backstage\Debug\Concerns\RecordsDebugTraffic;
use Backstage\Debug\Database\Factories\ExceptionFactory;
use Backstage\Debug\Debug;
use Backstage\Debug\Enums\ExceptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
            'fingerprint' => static::fingerprintFor($exception),
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
     * What was decided about this problem, if anything. It hangs off the
     * fingerprint, so every occurrence of one failure reads the same decision.
     *
     * @return BelongsTo<ExceptionState, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(ExceptionState::class, 'fingerprint', 'fingerprint');
    }

    /**
     * Everything nobody has put away yet: the problems without a decision, and
     * the ones that were marked fixed and happened again anyway.
     *
     * Written as a `scopeX` method rather than with the `#[Scope]` attribute,
     * which only exists from Laravel 12 and this package still supports 11.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $exceptions = $this->getTable();
        $states = (new ExceptionState)->getTable();

        $query->whereNotExists(function (QueryBuilder $state) use ($exceptions, $states): void {
            $state->selectRaw('1')
                ->from($states)
                ->whereColumn("{$states}.fingerprint", "{$exceptions}.fingerprint")
                ->where(function (QueryBuilder $state) use ($exceptions, $states): void {
                    $state->where("{$states}.status", ExceptionStatus::Ignored->value)
                        // A fix covers what happened before it and nothing
                        // after: an occurrence since is the failure telling you
                        // it was not fixed.
                        ->orWhere(fn (QueryBuilder $state): QueryBuilder => $state
                            ->where("{$states}.status", ExceptionStatus::Fixed->value)
                            ->whereColumn("{$exceptions}.occurred_at", '<=', "{$states}.marked_at"));
                });
        });
    }

    /**
     * Only the problems somebody put in this state, whenever they happened.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInState(Builder $query, ExceptionStatus $status): void
    {
        $query->whereHas('state', fn (Builder $state) => $state->where('status', $status));
    }

    public function status(): ?ExceptionStatus
    {
        return $this->state?->status;
    }

    /**
     * Whether this occurrence is one the table keeps out of the way. A fixed
     * problem that happened again is not: that is the whole point of marking it
     * fixed rather than ignoring it.
     */
    public function isPutAway(): bool
    {
        return match ($this->status()) {
            ExceptionStatus::Ignored => true,
            ExceptionStatus::Fixed => $this->occurred_at <= $this->state->marked_at,
            default => false,
        };
    }

    /**
     * Keep this problem out of the table until somebody asks for it. New
     * occurrences are still recorded — being ignored is a statement about how
     * interesting a failure is, not about whether it is worth knowing.
     */
    public function ignore(): ExceptionState
    {
        return $this->mark(ExceptionStatus::Ignored);
    }

    /**
     * Put this problem away as dealt with. Anything that happens after this
     * moment comes back into the table on its own.
     */
    public function markFixed(): ExceptionState
    {
        return $this->mark(ExceptionStatus::Fixed);
    }

    /**
     * Take back whatever was decided, which puts every occurrence of the
     * problem back in the table.
     */
    public function reopen(): void
    {
        ExceptionState::clear($this->fingerprint);

        $this->unsetRelation('state');
    }

    protected function mark(ExceptionStatus $status): ExceptionState
    {
        $state = ExceptionState::mark($this->fingerprint, $status);

        $this->setRelation('state', $state);

        return $state;
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
     *
     * Not called `fingerprint()`: Eloquent resolves an attribute by looking for
     * a method of that name, so a method named after a column is called with no
     * arguments the moment anything reads the column through a relation.
     */
    protected static function fingerprintFor(Throwable $exception): string
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
