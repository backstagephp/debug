<?php

namespace Backstage\Debug\Filament\Resources;

use BackedEnum;
use Backstage\Debug\Filament\Clusters\DebugCluster;
use Backstage\Debug\Filament\Concerns\FormatsDebugPayloads;
use Backstage\Debug\Filament\Resources\OutgoingRequestResource\Pages;
use Backstage\Debug\Models\OutgoingRequest;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class OutgoingRequestResource extends Resource
{
    use FormatsDebugPayloads;

    protected static ?string $model = OutgoingRequest::class;

    protected static ?string $cluster = DebugCluster::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-arrow-up-right';

    protected static ?string $navigationLabel = 'Outgoing requests';

    protected static ?string $modelLabel = 'outgoing request';

    protected static ?string $pluralModelLabel = 'outgoing requests';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return DebugCluster::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->tabs([
                        Tab::make('Call')
                            ->schema([
                                TextEntry::make('method')
                                    ->label('Method')
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->placeholder('No response')
                                    ->color(fn (?int $state): string => self::statusColor($state)),
                                TextEntry::make('duration_ms')
                                    ->label('Duration')
                                    ->placeholder('—')
                                    ->formatStateUsing(fn (int $state): string => "{$state} ms"),
                                TextEntry::make('occurred_at')
                                    ->label('Sent')
                                    ->dateTime('d M Y H:i:s')
                                    ->sinceTooltip(),
                                TextEntry::make('url')
                                    ->label('URL')
                                    ->fontFamily(FontFamily::Mono)
                                    ->copyable()
                                    ->columnSpanFull(),
                                TextEntry::make('error')
                                    ->label('Connection error')
                                    ->color('danger')
                                    ->visible(fn (OutgoingRequest $record): bool => filled($record->error))
                                    ->columnSpanFull(),
                            ])
                            ->columns(4),
                        Tab::make('Request')
                            ->schema([
                                KeyValueEntry::make('request_headers')
                                    ->label('Headers')
                                    ->placeholder('—'),
                                self::payloadEntry('request_body')
                                    ->label('Body')
                                    ->helperText(fn (OutgoingRequest $record): string => Number::fileSize($record->request_size)),
                            ])
                            ->columns(1),
                        Tab::make('Response')
                            ->schema([
                                KeyValueEntry::make('response_headers')
                                    ->label('Headers')
                                    ->placeholder('—'),
                                self::payloadEntry('response_body')
                                    ->label('Body')
                                    ->helperText(fn (OutgoingRequest $record): string => Number::fileSize($record->response_size)),
                            ])
                            ->columns(1),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('When')
                    ->since()
                    ->dateTimeTooltip('d M Y H:i:s')
                    ->sortable()
                    // The modal is hung off a column rather than a row button:
                    // that registers it as the table's view action, so clicking
                    // anywhere in the row opens it without a button per row.
                    ->action(ViewAction::make()
                        ->modal()
                        ->modalHeading(fn (OutgoingRequest $record): string => "{$record->method} {$record->host}")
                        ->modalDescription(fn (OutgoingRequest $record): ?string => $record->path)
                        ->modalWidth(Width::FiveExtraLarge)),
                TextColumn::make('method')
                    ->label('Method')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('host')
                    ->label('Host')
                    ->description(fn (OutgoingRequest $record): string => (string) str($record->path)->limit(60))
                    ->searchable(['host', 'url'])
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->placeholder('failed')
                    ->color(fn (?int $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => "{$state} ms")
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('response_size')
                    ->label('Response size')
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state))
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('request_size')
                    ->label('Request size')
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state))
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('host')
                    ->label('Host')
                    ->options(fn (): array => OutgoingRequest::query()
                        ->select('host')
                        ->distinct()
                        ->orderBy('host')
                        ->pluck('host', 'host')
                        ->all())
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('method')
                    ->label('Method')
                    ->options(fn (): array => OutgoingRequest::query()
                        ->select('method')
                        ->distinct()
                        ->orderBy('method')
                        ->pluck('method', 'method')
                        ->all())
                    ->multiple(),
                SelectFilter::make('status_group')
                    ->label('Status')
                    ->options([
                        '2xx' => 'Successful (2xx)',
                        '3xx' => 'Redirect (3xx)',
                        '4xx' => 'Client error (4xx)',
                        '5xx' => 'Server error (5xx)',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $query, string $group): Builder => $query
                            ->whereBetween('status', [((int) $group[0]) * 100, ((int) $group[0]) * 100 + 99]))),
                TernaryFilter::make('failed')
                    ->label('Outcome')
                    ->placeholder('All calls')
                    ->trueLabel('Failed only')
                    ->falseLabel('Succeeded only')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query
                            ->whereNull('status')
                            ->orWhere('status', '>=', 400)),
                        false: fn (Builder $query): Builder => $query->whereBetween('status', [200, 399]),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('slow')
                    ->label('Slower than a second')
                    ->query(fn (Builder $query): Builder => $query->where('duration_ms', '>=', 1000))
                    ->toggle(),
                self::recordedBetweenFilter('occurred_at'),
            ])
            ->recordUrl(null)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOutgoingRequests::route('/'),
            'view' => Pages\ViewOutgoingRequest::route('/{record}'),
        ];
    }
}
