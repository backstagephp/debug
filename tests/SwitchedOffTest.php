<?php

namespace Backstage\Debug\Tests;

use Backstage\Debug\Models\Exception;
use Backstage\Debug\Models\IncomingWebhook;
use Backstage\Debug\Models\Log;
use Backstage\Debug\Models\OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * A log that is switched off registers nothing at all — no listener, no
 * middleware, no reportable callback — so it costs the application nothing
 * rather than being written and then ignored.
 */
class SwitchedOffTest extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        config()->set('debug.record', [
            'exceptions' => false,
            'logs' => false,
            'outgoing_requests' => false,
            'incoming_webhooks' => false,
        ]);
    }

    public function test_nothing_is_recorded(): void
    {
        Route::post('/webhooks/example', fn (): array => ['status' => 'ok']);

        report(new RuntimeException('Something broke'));
        logger()->warning('Something looks off');

        Http::fake();
        Http::get('https://api.example.com/me');

        $this->postJson('/webhooks/example', ['id' => 'event_1'])->assertOk();

        $this->assertSame(0, Exception::query()->count());
        $this->assertSame(0, Log::query()->count());
        $this->assertSame(0, OutgoingRequest::query()->count());
        $this->assertSame(0, IncomingWebhook::query()->count());
    }
}
