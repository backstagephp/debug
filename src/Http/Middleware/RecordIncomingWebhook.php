<?php

namespace Backstage\Debug\Http\Middleware;

use Backstage\Debug\Models\IncomingWebhook;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Records deliveries to the webhook endpoints. It runs as global middleware and
 * decides on the path rather than being attached per route, so a delivery is
 * recorded even when it is turned away before it reaches a controller — a
 * failing signature check is exactly the kind of thing this log is for.
 */
class RecordIncomingWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldRecord($request)) {
            return $next($request);
        }

        $startedAt = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            IncomingWebhook::record(
                $request,
                $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500,
                $exception->getMessage(),
                microtime(true) - $startedAt,
            );

            throw $exception;
        }

        IncomingWebhook::record(
            $request,
            $response->getStatusCode(),
            IncomingWebhook::bodyOf($response),
            microtime(true) - $startedAt,
        );

        return $response;
    }

    protected function shouldRecord(Request $request): bool
    {
        return Str::is((array) config('debug.webhook_paths', []), $request->path());
    }
}
