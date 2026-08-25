<?php

namespace Backstage\Debug\Filament\Resources;

use BackedEnum;
use Backstage\Debug\Filament\Clusters\DebugCluster;
use Backstage\Debug\Filament\Concerns\FormatsDebugPayloads;
use Backstage\Debug\Filament\Resources\IncomingWebhookResource\Pages;
use Backstage\Debug\Models\IncomingWebhook;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class IncomingWebhookResource extends Resource
{
    use FormatsDebugPayloads;

    protected static ?string $model = IncomingWebhook::class;

    protected static ?string $cluster = DebugCluster::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-arrow-down-left';

    protected static ?string $navigationLabel = 'Incoming webhooks';

    protected static ?string $modelLabel = 'incoming webhook';

    protected static ?string $pluralModelLabel = 'incoming webhooks';

    protected static ?int $navigationSort = 4;

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
                        Tab::make('Delivery')
                            ->schema([
                                TextEntry::make('source')
                                    ->label('Source')
                                    ->badge()
                                    ->color('gray')
                                    ->formatStateUsing(fn (string $state): string => str($state)->title()->toString()),
                                TextEntry::make('status')
                                    ->label('Answered with')
                                    ->badge()
                                    ->color(fn (?int $state): string => self::statusColor($state)),
                                TextEntry::make('duration_ms')
                                    ->label('Handled in')
                                    ->placeholder('—')
                                    ->formatStateUsing(fn (int $state): string => "{$state} ms"),
                                TextEntry::make('received_at')
                                    ->label('Received')
                                    ->dateTime('d M Y H:i:s')
                                    ->sinceTooltip(),
                                TextEntry::make('url')
                                    ->label('URL')
                                    ->fontFamily(FontFamily::Mono)
                                    ->copyable()
                                    ->columnSpanFull(),
                                TextEntry::make('method')
                                    ->label('Method')
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('ip')
                                    ->label('From IP')
                                    ->placeholder('—'),
                                TextEntry::make('body_size')
                                    ->label('Body size')
                                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state)),
                                TextEntry::make('response_size')
                                    ->label('Response size')
                                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state)),
                            ])
                            ->columns(4),
                        Tab::make('Received')
                            ->schema([
                                KeyValueEntry::make('headers')
                                    ->label('Headers')
                                    ->placeholder('—'),
                                KeyValueEntry::make('query')
                                    ->label('Query string')
                                    ->placeholder('—'),
                                self::payloadEntry('payload')
                                    ->label('Body'),
                            ])
                            ->columns(1),
                        Tab::make('Answered')
                            ->schema([
                                self::payloadEntry('response_body')->hiddenLabel(),
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
                TextColumn::make('received_at')
                    ->label('When')
                    ->since()
                    ->dateTimeTooltip('d M Y H:i:s')
                    ->sortable()
                    // The modal is hung off a column rather than a row button:
                    // that registers it as the table's view action, so clicking
                    // anywhere in the row opens it without a button per row.
                    ->action(ViewAction::make()
                        ->modal()
                        ->modalHeading(fn (IncomingWebhook $record): string => str($record->source)->title()->toString().' webhook')
                        ->modalDescription(fn (IncomingWebhook $record): string => "{$record->method} {$record->path} — {$record->status}")
                        ->modalWidth(Width::FiveExtraLarge)),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => str($state)->title()->toString())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('path')
                    ->label('Endpoint')
                    ->description(fn (IncomingWebhook $record): string => $record->method)
                    ->searchable(['path', 'url'])
                    ->limit(50),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?int $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('body_size')
                    ->label('Body size')
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => "{$state} ms")
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ip')
                    ->label('From IP')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payload')
                    ->label('Body')
                    ->limit(60)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                SelectFilter::make('source')
                    ->label('Source')
                    ->options(fn (): array => IncomingWebhook::query()
                        ->select('source')
                        ->distinct()
                        ->orderBy('source')
                        ->pluck('source', 'source')
                        ->map(fn (string $source): string => str($source)->title()->toString())
                        ->all())
                    ->multiple(),
                SelectFilter::make('status_group')
                    ->label('Status')
                    ->options([
                        '2xx' => 'Accepted (2xx)',
                        '3xx' => 'Redirect (3xx)',
                        '4xx' => 'Rejected (4xx)',
                        '5xx' => 'Server error (5xx)',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $query, string $group): Builder => $query
                            ->whereBetween('status', [((int) $group[0]) * 100, ((int) $group[0]) * 100 + 99]))),
                TernaryFilter::make('accepted')
                    ->label('Outcome')
                    ->placeholder('All deliveries')
                    ->trueLabel('Accepted only')
                    ->falseLabel('Not accepted only')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereBetween('status', [200, 299]),
                        false: fn (Builder $query): Builder => $query->where('status', '>=', 300),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                self::recordedBetweenFilter('received_at'),
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
            'index' => Pages\ListIncomingWebhooks::route('/'),
            'view' => Pages\ViewIncomingWebhook::route('/{record}'),
        ];
    }
}
