<?php

namespace Backstage\Debug\Listeners;

use Backstage\Debug\Models\Log;
use Illuminate\Log\Events\MessageLogged;

/**
 * Writes a row for every line the application logs. Registered by the service
 * provider rather than discovered, since a package's listeners are outside the
 * directory Laravel scans.
 */
class RecordLog
{
    public function handle(MessageLogged $event): void
    {
        Log::record($event->level, $event->message, $event->context);
    }
}
