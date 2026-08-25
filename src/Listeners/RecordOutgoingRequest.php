<?php

namespace Backstage\Debug\Listeners;

use Backstage\Debug\Models\OutgoingRequest;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;

/**
 * Writes a row for every call the HTTP client finishes, whether the other side
 * answered or could not be reached at all. Both methods are registered by the
 * service provider, since a package's listeners are outside the directory
 * Laravel scans.
 */
class RecordOutgoingRequest
{
    public function handleResponseReceived(ResponseReceived $event): void
    {
        OutgoingRequest::record($event->request, $event->response);
    }

    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        OutgoingRequest::record($event->request, exception: $event->exception);
    }
}
