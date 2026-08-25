<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What Is Recorded
    |--------------------------------------------------------------------------
    |
    | Each of the four logs can be switched off on its own, so an application
    | only records what it actually reads back. A log that is off hangs on no
    | listener and no middleware, and writes no rows.
    |
    */

    'record' => [
        'exceptions' => true,
        'logs' => true,
        'outgoing_requests' => true,
        'incoming_webhooks' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Paths
    |--------------------------------------------------------------------------
    |
    | Requests matching one of these patterns are recorded as incoming
    | webhooks. Matching on the path rather than on a route keeps deliveries to
    | endpoints registered by a package in the log as well.
    |
    */

    'webhook_paths' => [
        'webhooks/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Redacted Headers
    |--------------------------------------------------------------------------
    |
    | Headers whose value is replaced before it is stored. Anyone who can read
    | the debug tables can read every request that passed through them, so the
    | headers that carry a credential never make it into the database.
    |
    */

    'redacted_headers' => [
        'authorization',
        'cookie',
        'set-cookie',
        'proxy-authorization',
        'x-api-key',
        'x-auth-token',
        'x-hub-signature',
        'x-hub-signature-256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum Body Length
    |--------------------------------------------------------------------------
    |
    | Request and response bodies are stored up to this many characters. The
    | recorded size is always the size of the full body, so a truncated body
    | still reads as the response that was actually received.
    |
    */

    'max_body_length' => 65536,

    /*
    |--------------------------------------------------------------------------
    | Maximum Trace Length
    |--------------------------------------------------------------------------
    */

    'max_trace_length' => 65536,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many days of history the debug tables keep. Every log is prunable, so
    | `model:prune` drops everything older and the tables stay useful for "what
    | is broken right now" without growing without end.
    |
    | With `prune` on, the package schedules that daily run itself. Switch it
    | off to list the models in an existing `model:prune` of your own.
    |
    */

    'retention_days' => 8,

    'prune' => true,

    /*
    |--------------------------------------------------------------------------
    | The User Behind a Row
    |--------------------------------------------------------------------------
    |
    | Exceptions and log lines record who was signed in at the time. Leave the
    | model null to follow the default authentication provider.
    |
    | A user is shown by `name_attribute` and searched by `search_columns`,
    | which are two different things as soon as the name is composed of columns
    | rather than being one: a table cannot search what the database does not
    | have a column for.
    |
    */

    'user' => [
        'model' => null,
        'name_attribute' => 'name',
        'search_columns' => ['name'],
    ],

];
