<?php

namespace Backstage\Debug\Filament\Concerns;

use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * The parts the four debug logs show in the same way: a raw body, a status
 * code and a "recorded between" filter.
 */
trait FormatsDebugPayloads
{
    /**
     * A body or a stack trace, shown as it was stored. JSON is laid out first,
     * because almost everything these endpoints send and receive is JSON and
     * one long line is unreadable.
     */
    protected static function payloadEntry(string $name): TextEntry
    {
        return TextEntry::make($name)
            ->placeholder('—')
            ->fontFamily(FontFamily::Mono)
            ->size(TextSize::ExtraSmall)
            ->copyable()
            // Written as a style rather than as utility classes so a body keeps
            // its line breaks without the panel's CSS having to be rebuilt.
            ->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere;'])
            ->formatStateUsing(fn (?string $state): string => static::prettyPrint($state));
    }

    /**
     * Lay out a JSON body over multiple lines, leaving anything that is not
     * JSON exactly as it was received.
     */
    protected static function prettyPrint(?string $value): string
    {
        if (blank($value)) {
            return '';
        }

        $decoded = json_decode((string) $value, associative: true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return (string) $value;
        }

        return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The colour of an HTTP status code, so a table of them can be read at a
     * glance rather than digit by digit.
     */
    protected static function statusColor(?int $status): string
    {
        return match (true) {
            $status === null => 'danger',
            $status >= 500 => 'danger',
            $status >= 400 => 'warning',
            $status >= 300 => 'info',
            $status >= 200 => 'success',
            default => 'gray',
        };
    }

    /**
     * Narrow the table to a window of time, which is how a report of "it broke
     * this morning" is followed up.
     */
    protected static function recordedBetweenFilter(string $column): Filter
    {
        return Filter::make('recorded_between')
            ->schema([
                DatePicker::make('from')->label('From'),
                DatePicker::make('until')->label('Until'),
            ])
            ->columns(2)
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate($column, '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate($column, '<=', $date)))
            ->indicateUsing(function (array $data): array {
                $indicators = [];

                if (filled($data['from'] ?? null)) {
                    $indicators[] = 'From '.$data['from'];
                }

                if (filled($data['until'] ?? null)) {
                    $indicators[] = 'Until '.$data['until'];
                }

                return $indicators;
            });
    }
}
