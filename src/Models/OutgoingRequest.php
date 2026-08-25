<?php

namespace Backstage\Debug\Models;

use Backstage\Debug\Concerns\RecordsDebugTraffic;
use Backstage\Debug\Database\Factories\OutgoingRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Every call the application makes to somebody else's API, with what was sent
 * and what came back.
 *
 * @property int $id
 * @property string $method
 * @property string $url
 * @property string $host
 * @property string|null $path
 * @property array<string, string>|null $request_headers
 * @property string|null $request_body
 * @property int $request_size
 * @property int|null $status
 * @property array<string, string>|null $response_headers
 * @property string|null $response_body
 * @property int $response_size
 * @property int|null $duration_ms
 * @property string|null $error
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OutgoingRequest extends Model
{
    /** @use HasFactory<OutgoingRequestFactory> */
    use HasFactory;

    use MassPrunable;
    use RecordsDebugTraffic;

    protected $table = 'outgoing_requests';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'method',
        'url',
        'host',
        'path',
        'request_headers',
        'request_body',
        'request_size',
        'status',
        'response_headers',
        'response_body',
        'response_size',
        'duration_ms',
        'error',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'response_headers' => 'array',
            'request_size' => 'integer',
            'response_size' => 'integer',
            'status' => 'integer',
            'duration_ms' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Record a finished call. A call that never reached the other side has no
     * response and carries the connection error instead, which is the
     * difference between "they answered badly" and "we could not reach them".
     */
    public static function record(Request $request, ?Response $response = null, ?Throwable $exception = null): ?self
    {
        $url = $request->url();
        $requestBody = static::bodyOf($request);
        $responseBody = $response?->body();

        return static::write([
            'method' => $request->method(),
            'url' => $url,
            'host' => (string) (parse_url($url, PHP_URL_HOST) ?: $url),
            'path' => (string) (parse_url($url, PHP_URL_PATH) ?: '/'),
            'request_headers' => static::redactHeaders($request->headers()),
            'request_body' => static::truncate($requestBody),
            'request_size' => strlen((string) $requestBody),
            'status' => $response?->status(),
            'response_headers' => $response === null ? null : static::redactHeaders($response->headers()),
            'response_body' => static::truncate($responseBody),
            'response_size' => strlen((string) $responseBody),
            'duration_ms' => static::durationOf($response),
            'error' => $exception?->getMessage(),
            'occurred_at' => now(),
        ]);
    }

    public function wasSuccessful(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('occurred_at', '<', static::retentionCutoff());
    }

    /**
     * How long the call took, in milliseconds. Guzzle reports this on the
     * transfer itself, so it is the time on the wire rather than the time
     * around the call. It is missing for a faked or a failed response.
     */
    protected static function durationOf(?Response $response): ?int
    {
        $seconds = $response?->handlerStats()['total_time'] ?? null;

        return is_numeric($seconds) ? (int) round(((float) $seconds) * 1000) : null;
    }

    /**
     * The body that was sent. A streamed or uploaded body is not read back:
     * rewinding it here would change what the request actually sends.
     */
    protected static function bodyOf(Request $request): ?string
    {
        if ($request->isMultipart()) {
            return null;
        }

        try {
            return $request->body();
        } catch (Throwable) {
            return null;
        }
    }

    protected static function newFactory(): Factory
    {
        return OutgoingRequestFactory::new();
    }
}
