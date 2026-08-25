# Debug

Read back what your Laravel application actually did, without opening a log file on the server.

Four tables in a Filament cluster:

- **Exceptions** — every exception the application reported, with the request or command it came out of, fingerprinted so the same failure can be narrowed down to one problem, and put away once you have decided what it is worth.
- **Logs** — every line the application logged, with its PSR-3 level as a number so "warnings and worse" is one filter.
- **Outgoing requests** — every call the HTTP client made, with what was sent, what came back, and how long it took — including the calls that never reached the other side.
- **Incoming webhooks** — every delivery to your webhook endpoints, including the ones that were turned away, since a webhook that stops working usually stops at the signature check.

Credentials are never stored: the headers that carry one are replaced before the row is written. Bodies are truncated to a readable length while the recorded size stays the size of the full body. Everything ages out after a retention window, so the tables answer "what is broken right now" without growing without end.

A failure of the recording never reaches the code it observes: an unreachable or unmigrated database leaves the request it was watching untouched.

## Putting an exception away

An exceptions table that only grows is one nobody reads. Two decisions keep it worth opening, and both are about the problem rather than the row you were looking at — they cover every occurrence that shares its fingerprint, including the ones that have not happened yet:

- **Ignore** — known, not worth looking at. Occurrences keep being recorded; they just stay out of the table.
- **Mark as fixed** — dealt with. Everything up to that moment leaves the table, and anything that happens *after* it comes back on its own. A fix that did not hold tells you so without you having to remember to check.

Both are offered from the modal you read an exception in, from the record's own page, and as bulk actions on a selection — where a selection is decided about per problem, so picking two occurrences of one failure and one of another is two decisions, not three. **Reopen** takes a decision back.

The table opens on what nobody has put away yet. The rest is behind the status filter rather than gone, and the navigation badge counts only what still wants an answer.

## Installation

```bash
composer require backstage/debug
php artisan debug:install
```

The install command publishes the config file and the migrations and offers to run them.

## Registering the panel

Add the plugin to the Filament panel the logs should be readable from:

```php
use Backstage\Debug\DebugPlugin;

$panel->plugins([
    DebugPlugin::make()
        ->authorize(fn (): bool => (bool) auth()->user()?->is_admin),
]);
```

Everything recorded here is readable by whoever can open the tables — request bodies, log context, the URLs somebody visited — so `authorize()` is where you decide who that is. It defaults to everyone who can reach the panel, which is almost never what you want in production.

The cluster claims no item in the panel's navigation by default; it is a place you go looking for when something is wrong. Reach it from the user menu:

```php
use Backstage\Debug\Filament\Clusters\DebugCluster;

$panel->userMenuItems([
    Action::make('debug')
        ->label('Debug')
        ->url(fn (): string => DebugCluster::getUrl())
        ->icon('lucide-bug')
        ->visible(fn (): bool => DebugCluster::canAccess()),
]);
```

Or give it a navigation item after all with `DebugPlugin::make()->navigationItem()`.

## Configuration

`config/debug.php` holds:

- `record` — each of the four logs can be switched off on its own, so an application only records what it actually reads back.
- `webhook_paths` — the request paths recorded as incoming webhooks. Matching on the path rather than on a route keeps deliveries to endpoints registered by a package in the log as well.
- `redacted_headers` — the headers whose value is replaced before it is stored.
- `max_body_length` and `max_trace_length` — how much of a body or a stack trace is kept.
- `retention_days` and `prune` — how long the history is kept, and whether the package schedules the daily `model:prune` itself. Switch `prune` off to list the four models in an existing prune of your own.
- `user` — the model behind the `user_id` of an exception or a log line, the attribute a user is shown by, and the columns they are searched by. A name composed of a first and a last name has no column of its own to search, which is why those are two settings rather than one.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
