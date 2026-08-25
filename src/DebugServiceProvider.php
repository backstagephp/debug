<?php

namespace Backstage\Debug;

use Backstage\Debug\Http\Middleware\RecordIncomingWebhook;
use Backstage\Debug\Listeners\RecordLog;
use Backstage\Debug\Listeners\RecordOutgoingRequest;
use Backstage\Debug\Models\Exception;
use Backstage\Debug\Models\IncomingWebhook;
use Backstage\Debug\Models\Log;
use Backstage\Debug\Models\OutgoingRequest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class DebugServiceProvider extends PackageServiceProvider
{
    public static string $name = 'debug';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasMigrations(
                'create_exceptions_table',
                'create_outgoing_requests_table',
                'create_incoming_webhooks_table',
                'create_logs_table',
            )
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('backstagephp/debug');
            });
    }

    /**
     * The exception hook is laid down in the register phase rather than the
     * boot phase: a package that wraps the exception handler — Collision does,
     * outside of tests — resolves it while registering, and a callback hung on
     * afterwards would never reach the handler doing the reporting.
     */
    public function packageRegistered(): void
    {
        $this->recordExceptions();
    }

    public function packageBooted(): void
    {
        $this->recordLogs();
        $this->recordOutgoingRequests();
        $this->recordIncomingWebhooks();
        $this->pruneWhatHasAgedOut();
    }

    /**
     * Keep every reported exception in the database as well as in the log, so
     * it can be searched and filtered instead of grepped out of a file on the
     * server.
     *
     * Registered as a reportable callback that returns nothing, which leaves
     * the rest of the reporting — the log channels, whatever else is listening
     * — exactly as it was. It is hung on the handler as it is resolved, the
     * same way `withExceptions()` does it, and on the handler that is already
     * there: whether it has been resolved yet depends on which other packages
     * are installed, and the callback belongs on it either way.
     *
     * Whether the log is switched on is read when an exception is reported
     * rather than here: this runs in the register phase, before the
     * configuration of the application it is installed in is settled.
     */
    protected function recordExceptions(): void
    {
        $record = function (Handler $handler): void {
            $handler->reportable(function (Throwable $exception): void {
                if (Debug::records('exceptions')) {
                    Exception::record($exception);
                }
            });
        };

        $this->app->afterResolving(Handler::class, $record);

        if (! $this->app->resolved(ExceptionHandler::class)) {
            return;
        }

        $handler = $this->app->make(ExceptionHandler::class);

        if ($handler instanceof Handler) {
            $record($handler);
        }
    }

    protected function recordLogs(): void
    {
        if (! Debug::records('logs')) {
            return;
        }

        Event::listen(MessageLogged::class, RecordLog::class);
    }

    protected function recordOutgoingRequests(): void
    {
        if (! Debug::records('outgoing_requests')) {
            return;
        }

        Event::listen(ResponseReceived::class, [RecordOutgoingRequest::class, 'handleResponseReceived']);
        Event::listen(ConnectionFailed::class, [RecordOutgoingRequest::class, 'handleConnectionFailed']);
    }

    /**
     * Appended to the global middleware rather than attached per route, so a
     * delivery is recorded even when it is turned away before it reaches a
     * controller — a failing signature check is exactly the kind of thing this
     * log is for.
     */
    protected function recordIncomingWebhooks(): void
    {
        if (! Debug::records('incoming_webhooks')) {
            return;
        }

        $kernel = $this->app->make(Kernel::class);

        if ($kernel instanceof HttpKernel) {
            $kernel->pushMiddleware(RecordIncomingWebhook::class);
        }
    }

    /**
     * A debug log answers "what is going wrong now", so it is worth nothing
     * once it is old enough that nobody is looking for it any more.
     */
    protected function pruneWhatHasAgedOut(): void
    {
        if (! config('debug.prune', true) || ! $this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command('model:prune', ['--model' => [
                    Exception::class,
                    Log::class,
                    OutgoingRequest::class,
                    IncomingWebhook::class,
                ]])
                ->daily();
        });
    }
}
