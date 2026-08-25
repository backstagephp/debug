<?php

namespace Backstage\Debug\Tests;

use Backstage\Debug\Models\Exception;
use Backstage\Debug\Models\IncomingWebhook;
use Backstage\Debug\Models\Log;
use Backstage\Debug\Models\OutgoingRequest;
use Backstage\Debug\Tests\Fixtures\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Lines are written through the `logger()` helper rather than through the `Log`
 * facade, which would collide with the model of the same name.
 */
class RecordingTest extends TestCase
{
    public function test_a_reported_exception_is_written_to_the_debug_log(): void
    {
        report(new RuntimeException('Something broke'));

        $exception = Exception::query()->sole();

        $this->assertSame(RuntimeException::class, $exception->exception_class);
        $this->assertSame('Something broke', $exception->message);
        $this->assertSame(__FILE__, $exception->file);
        $this->assertNotNull($exception->trace);
        $this->assertNotNull($exception->occurred_at);
    }

    public function test_an_exception_thrown_during_a_request_records_the_request_and_the_user(): void
    {
        Route::get('/boom', fn () => throw new RuntimeException('Boom'));

        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $this->actingAs($user)->get('/boom')->assertServerError();

        $exception = Exception::query()->sole();

        $this->assertSame(Exception::SOURCE_HTTP, $exception->source);
        $this->assertSame('GET', $exception->method);
        $this->assertStringEndsWith('/boom', (string) $exception->url);
        $this->assertSame($user->id, $exception->user_id);
    }

    public function test_the_same_failure_keeps_the_same_fingerprint(): void
    {
        $throw = fn (string $message) => new RuntimeException($message);

        report($throw('First time'));
        report($throw('Second time, different message'));

        $this->assertCount(2, Exception::query()->get());
        $this->assertCount(1, Exception::query()->pluck('fingerprint')->unique());
    }

    public function test_a_logged_line_is_written_to_the_debug_log(): void
    {
        logger()->warning('Something looks off', ['order' => 42]);

        $entry = Log::query()->sole();

        $this->assertSame('warning', $entry->level);
        $this->assertSame(Log::SEVERITIES['warning'], $entry->severity);
        $this->assertSame('Something looks off', $entry->message);
        $this->assertSame('{"order":42}', $entry->context);
        $this->assertTrue($entry->isProblem());
    }

    public function test_every_level_is_recorded_with_its_severity(): void
    {
        foreach (array_keys(Log::SEVERITIES) as $level) {
            logger()->log($level, "A {$level} line");
        }

        $severities = Log::query()->orderBy('id')->pluck('severity', 'level')->all();

        $this->assertSame(Log::SEVERITIES, $severities);
        $this->assertFalse(Log::query()->where('level', 'info')->sole()->isProblem());
    }

    public function test_an_exception_in_the_context_is_stored_as_something_readable(): void
    {
        logger()->error('It broke', ['exception' => new RuntimeException('Boom')]);

        $entry = Log::query()->sole();

        $this->assertStringContainsString('RuntimeException', (string) $entry->context);
        $this->assertStringContainsString('Boom', (string) $entry->context);
    }

    public function test_a_call_is_recorded_with_what_was_sent_and_what_came_back(): void
    {
        Http::fake([
            'api.example.com/*' => Http::response(['ok' => true], 201, ['Content-Type' => 'application/json']),
        ]);

        Http::post('https://api.example.com/v1/things', ['name' => 'Thing']);

        $request = OutgoingRequest::query()->sole();

        $this->assertSame('POST', $request->method);
        $this->assertSame('api.example.com', $request->host);
        $this->assertSame('/v1/things', $request->path);
        $this->assertSame(201, $request->status);
        $this->assertStringContainsString('Thing', (string) $request->request_body);
        $this->assertStringContainsString('"ok"', (string) $request->response_body);
        $this->assertTrue($request->wasSuccessful());
    }

    public function test_credentials_are_never_stored(): void
    {
        Http::fake();

        Http::withHeaders(['Authorization' => 'Bearer secret-token'])->get('https://api.example.com/me');

        $request = OutgoingRequest::query()->sole();

        $this->assertSame('[redacted]', $request->request_headers['Authorization']);
        $this->assertStringNotContainsString('secret-token', (string) json_encode($request->request_headers));
    }

    public function test_a_call_that_never_reached_the_other_side_records_the_error(): void
    {
        Http::fake(['api.example.com/*' => Http::failedConnection('Operation timed out')]);

        try {
            Http::get('https://api.example.com/slow');
        } catch (ConnectionException) {
            // The failure itself is what is being recorded.
        }

        $request = OutgoingRequest::query()->sole();

        $this->assertNull($request->status);
        $this->assertStringContainsString('timed out', (string) $request->error);
        $this->assertFalse($request->wasSuccessful());
    }

    public function test_a_body_larger_than_the_limit_is_truncated_but_its_size_is_kept(): void
    {
        config()->set('debug.max_body_length', 50);

        Http::fake(['api.example.com/*' => Http::response(str_repeat('a', 500))]);

        Http::get('https://api.example.com/large');

        $request = OutgoingRequest::query()->sole();

        $this->assertSame(500, $request->response_size);
        $this->assertLessThan(500, strlen((string) $request->response_body));
        $this->assertStringEndsWith('truncated]', (string) $request->response_body);
    }

    public function test_a_delivery_to_a_webhook_endpoint_is_recorded_with_its_payload_and_its_answer(): void
    {
        Route::post('/webhooks/example', fn (): array => ['status' => 'ok']);

        $this->postJson('/webhooks/example', ['id' => 'event_1'])->assertOk();

        $webhook = IncomingWebhook::query()->sole();

        $this->assertSame('example', $webhook->source);
        $this->assertSame('POST', $webhook->method);
        $this->assertSame('/webhooks/example', $webhook->path);
        $this->assertSame(200, $webhook->status);
        $this->assertStringContainsString('event_1', (string) $webhook->payload);
        $this->assertStringContainsString('ok', (string) $webhook->response_body);
        $this->assertGreaterThan(0, $webhook->body_size);
        $this->assertTrue($webhook->wasAccepted());
    }

    public function test_a_delivery_that_is_turned_away_is_recorded_too(): void
    {
        Route::post('/webhooks/example', fn () => abort(403));

        $this->postJson('/webhooks/example', ['id' => 'event_1'])->assertForbidden();

        $webhook = IncomingWebhook::query()->sole();

        $this->assertSame(403, $webhook->status);
        $this->assertFalse($webhook->wasAccepted());
    }

    public function test_signature_headers_are_never_stored(): void
    {
        config()->set('debug.redacted_headers', ['x-hub-signature-256']);

        Route::post('/webhooks/example', fn (): array => ['status' => 'ok']);

        $this->withHeaders(['X-Hub-Signature-256' => 'sha256=deadbeef'])
            ->postJson('/webhooks/example', ['id' => 'event_1'])
            ->assertOk();

        $webhook = IncomingWebhook::query()->sole();

        $this->assertSame('[redacted]', $webhook->headers['x-hub-signature-256']);
        $this->assertStringNotContainsString('deadbeef', (string) json_encode($webhook->headers));
    }

    public function test_requests_outside_the_webhook_paths_are_not_recorded(): void
    {
        Route::get('/something-else', fn (): string => 'ok');

        $this->get('/something-else')->assertOk();

        $this->assertSame(0, IncomingWebhook::query()->count());
    }

    public function test_every_log_is_pruned_once_it_is_past_the_retention_window(): void
    {
        $stale = now()->subDays(9);
        $fresh = now()->subDays(7);

        Exception::factory()->create(['occurred_at' => $stale]);
        Log::factory()->create(['logged_at' => $stale]);
        OutgoingRequest::factory()->create(['occurred_at' => $stale]);
        IncomingWebhook::factory()->create(['received_at' => $stale]);

        $kept = [
            Exception::factory()->create(['occurred_at' => $fresh]),
            Log::factory()->create(['logged_at' => $fresh]),
            OutgoingRequest::factory()->create(['occurred_at' => $fresh]),
            IncomingWebhook::factory()->create(['received_at' => $fresh]),
        ];

        $this->artisan('model:prune', ['--model' => [
            Exception::class,
            Log::class,
            OutgoingRequest::class,
            IncomingWebhook::class,
        ]])->assertSuccessful();

        $this->assertSame([$kept[0]->id], Exception::query()->pluck('id')->all());
        $this->assertSame([$kept[1]->id], Log::query()->pluck('id')->all());
        $this->assertSame([$kept[2]->id], OutgoingRequest::query()->pluck('id')->all());
        $this->assertSame([$kept[3]->id], IncomingWebhook::query()->pluck('id')->all());
    }
}
