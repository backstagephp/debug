<?php

namespace Backstage\Debug\Filament\Resources;

use BackedEnum;
use Backstage\Debug\Debug;
use Backstage\Debug\Filament\Clusters\DebugCluster;
use Backstage\Debug\Filament\Concerns\FormatsDebugPayloads;
use Backstage\Debug\Filament\Resources\LogResource\Pages;
use Backstage\Debug\Models\Log;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LogResource extends Resource
{
    use FormatsDebugPayloads;

    protected static ?string $model = Log::class;

    protected static ?string $cluster = DebugCluster::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-scroll-text';

    protected static ?string $navigationLabel = 'Logs';

    protected static ?string $modelLabel = 'log line';

    protected static ?string $pluralModelLabel = 'log lines';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return DebugCluster::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The colour of a log level, read off its PSR-3 severity so the scale is
     * the same one the levels themselves are ordered by.
     */
    protected static function levelColor(int $severity): string
    {
        return match (true) {
            $severity >= Log::SEVERITIES['error'] => 'danger',
            $severity >= Log::SEVERITIES['warning'] => 'warning',
            $severity >= Log::SEVERITIES['info'] => 'info',
            default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->tabs([
                        Tab::make('Entry')
                            ->schema([
                                TextEntry::make('level')
                                    ->label('Level')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => str($state)->title()->toString())
                                    ->color(fn (Log $record): string => self::levelColor($record->severity)),
                                TextEntry::make('logged_at')
                                    ->label('Logged')
                                    ->dateTime('d M Y H:i:s')
                                    ->sinceTooltip(),
                                TextEntry::make('source')
                                    ->label('Source')
                                    ->badge()
                                    ->color('gray')
                                    ->formatStateUsing(fn (string $state): string => str($state)->title()->toString()),
                                TextEntry::make('user.'.Debug::userNameAttribute())
                                    ->label('User')
                                    ->placeholder('—'),
                                self::payloadEntry('message')
                                    ->label('Message')
                                    ->columnSpanFull(),
                                TextEntry::make('url')
                                    ->label('URL')
                                    ->placeholder('—')
                                    ->copyable()
                                    ->columnSpanFull(),
                                TextEntry::make('command')
                                    ->label('Command')
                                    ->fontFamily(FontFamily::Mono)
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                            ])
                            ->columns(4),
                        Tab::make('Context')
                            ->schema([
                                self::payloadEntry('context')->hiddenLabel(),
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
                TextColumn::make('logged_at')
                    ->label('When')
                    ->since()
                    ->dateTimeTooltip('d M Y H:i:s')
                    ->sortable()
                    // The modal is hung off a column rather than a row button:
                    // that registers it as the table's view action, so clicking
                    // anywhere in the row opens it without a button per row.
                    ->action(ViewAction::make()
                        ->modal()
                        ->modalHeading(fn (Log $record): string => str($record->level)->title()->toString())
                        ->modalDescription(fn (Log $record): string => str($record->message)->limit(120)->toString())
                        ->modalWidth(Width::FiveExtraLarge)),
                TextColumn::make('level')
                    ->label('Level')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->title()->toString())
                    ->color(fn (Log $record): string => self::levelColor($record->severity))
                    // Sorted on the severity so the levels come out in order of
                    // how bad they are rather than alphabetically.
                    ->sortable(['severity']),
                TextColumn::make('message')
                    ->label('Message')
                    ->limit(90)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => str($state)->title()->toString())
                    ->sortable(),
                TextColumn::make('url')
                    ->label('URL')
                    ->limit(50)
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('context')
                    ->label('Context')
                    ->limit(60)
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.'.Debug::userNameAttribute())
                    ->label('User')
                    ->placeholder('—')
                    ->searchable(Debug::userSearchColumns())
                    ->toggleable(),
            ])
            ->defaultSort('logged_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->filters([
                SelectFilter::make('level')
                    ->label('Level')
                    ->options(fn (): array => collect(Log::SEVERITIES)
                        ->keys()
                        ->mapWithKeys(fn (string $level): array => [$level => str($level)->title()->toString()])
                        ->all())
                    ->multiple(),
                Filter::make('problems')
                    ->label('Warnings and worse')
                    ->query(fn (Builder $query): Builder => $query->where('severity', '>=', Log::SEVERITIES['warning']))
                    ->toggle(),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        Log::SOURCE_HTTP => 'HTTP',
                        Log::SOURCE_CONSOLE => 'Console',
                    ]),
                self::recordedBetweenFilter('logged_at'),
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
            'index' => Pages\ListLogs::route('/'),
            'view' => Pages\ViewLog::route('/{record}'),
        ];
    }
}
