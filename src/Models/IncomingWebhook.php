<?php

namespace Backstage\Debug\Models;

use Backstage\Debug\Concerns\RecordsDebugTraffic;
use Backstage\Debug\Database\Factories\IncomingWebhookFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every delivery to one of the application's webhook endpoints, with what was
 * received and what was answered — including the ones that were turned away,
 * since a webhook that stops working usually stops at the signature check.
 *
 * @property int $id
 * @property string $source
 * @property string $method
 * @property string $path
 * @property string $url
 * @property string|null $ip
 * @property array<string, string>|null $headers
 * @property array<string, mixed>|null $query
 * @property string|null $payload
 * @property int $body_size
 * @property int $status
 * @property string|null $response_body
 * @property int $response_size
 * @property int|null $duration_ms
 * @property Carbon $received_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class IncomingWebhook extends Model
{
    /** @use HasFactory<IncomingWebhookFactory> */
    use HasFactory;

    use MassPrunable;
    use RecordsDebugTraffic;

    protected $table = 'incoming_webhooks';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'method',
        'path',
        'url',
        'ip',
        'headers',
        'query',
        'payload',
        'body_size',
        'status',
        'response_body',
        'response_size',
        'duration_ms',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'query' => 'array',
            'body_size' => 'integer',
            'response_size' => 'integer',
            'status' => 'integer',
            'duration_ms' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    /**
     * Record a delivery once its response is known.
     */
    public static function record(Request $request, int $status, ?string $responseBody, float $durationInSeconds): ?self
    {
        $payload = $request->getContent();

        return static::write([
            'source' => static::sourceOf($request),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'headers' => static::redactHeaders($request->headers->all()),
            'query' => $request->query() ?: null,
            'payload' => static::truncate($payload),
            'body_size' => strlen($payload),
            'status' => $status,
            'response_body' => static::truncate($responseBody),
            'response_size' => strlen((string) $responseBody),
            'duration_ms' => (int) round($durationInSeconds * 1000),
            'received_at' => now(),
        ]);
    }

    public function wasAccepted(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('received_at', '<', static::retentionCutoff());
    }

    /**
     * The party that delivered it. Every endpoint lives under `webhooks/`, so
     * the segment behind it names the integration — including the endpoints a
     * package registers, which have no route name of ours to read.
     */
    protected static function sourceOf(Request $request): string
    {
        return $request->segment(2) ?? 'unknown';
    }

    /**
     * The body of a response worth keeping. Anything that is not text — a file
     * download, a redirect without a body — says nothing here.
     */
    public static function bodyOf(Response $response): ?string
    {
        $content = $response->getContent();

        return $content === false ? null : $content;
    }

    protected static function newFactory(): Factory
    {
        return IncomingWebhookFactory::new();
    }
}
