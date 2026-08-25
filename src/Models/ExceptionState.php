<?php

namespace Backstage\Debug\Models;

use Backstage\Debug\Debug;
use Backstage\Debug\Enums\ExceptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * What somebody decided about a problem: ignored, or fixed. One row per
 * fingerprint, so the decision covers every occurrence of that failure —
 * including the ones that have not happened yet.
 *
 * @property int $id
 * @property string $fingerprint
 * @property ExceptionStatus $status
 * @property Carbon $marked_at
 * @property int|string|null $user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ExceptionState extends Model
{
    protected $table = 'exception_states';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'fingerprint',
        'status',
        'marked_at',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExceptionStatus::class,
            'marked_at' => 'datetime',
        ];
    }

    /**
     * Put a problem in a state, or move it to another one. Marking again is
     * what a fix that did not hold needs: the moment moves up, so the
     * occurrences since the last attempt are covered too.
     */
    public static function mark(string $fingerprint, ExceptionStatus $status): self
    {
        return static::query()->updateOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'status' => $status,
                'marked_at' => now(),
                'user_id' => Auth::id(),
            ],
        );
    }

    /**
     * Drop the decision, which puts every occurrence of the problem back in
     * the table.
     */
    public static function clear(string $fingerprint): void
    {
        static::query()->where('fingerprint', $fingerprint)->delete();
    }

    /**
     * @return HasMany<Exception, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(Exception::class, 'fingerprint', 'fingerprint');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Debug::userModel());
    }
}
