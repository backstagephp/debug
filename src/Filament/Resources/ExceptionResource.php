<?php

namespace Backstage\Debug\Filament\Resources;

use BackedEnum;
use Backstage\Debug\Debug;
use Backstage\Debug\Filament\Clusters\DebugCluster;
use Backstage\Debug\Filament\Concerns\FormatsDebugPayloads;
use Backstage\Debug\Filament\Resources\ExceptionResource\Pages;
use Backstage\Debug\Models\Exception;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExceptionResource extends Resource
{
    use FormatsDebugPayloads;

    protected static ?string $model = Exception::class;

    protected static ?string $cluster = DebugCluster::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-circle-alert';

    protected static ?string $navigationLabel = 'Exceptions';

    protected static ?string $modelLabel = 'exception';

    protected static ?string $pluralModelLabel = 'exceptions';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return DebugCluster::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Exception::query()->where('occurred_at', '>=', now()->subDay())->count();

        return $count === 0 ? null : (string) $count;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->tabs([
                        Tab::make('Exception')
                            ->schema([
                                TextEntry::make('exception_class')
                                    ->label('Class')
                                    ->fontFamily(FontFamily::Mono)
                                    ->copyable(),
                                TextEntry::make('occurred_at')
                                    ->label('Occurred')
                                    ->dateTime('d M Y H:i:s')
                                    ->sinceTooltip(),
                                TextEntry::make('code')
                                    ->label('Code')
                                    ->placeholder('—'),
                                TextEntry::make('fingerprint')
                                    ->label('Fingerprint')
                                    ->fontFamily(FontFamily::Mono)
                                    ->helperText('The same failure keeps the same fingerprint.'),
                                self::payloadEntry('message')
                                    ->label('Message')
                                    ->columnSpanFull(),
                                TextEntry::make('file')
                                    ->label('Thrown in')
                                    ->fontFamily(FontFamily::Mono)
                                    ->formatStateUsing(fn (?string $state, Exception $record): string => trim("{$state}:{$record->line}", ':'))
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Tab::make('Where it happened')
                            ->schema([
                                TextEntry::make('source')
                                    ->label('Source')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => str($state)->title()->toString()),
                                TextEntry::make('method')
                                    ->label('Method')
                                    ->placeholder('—'),
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
                                TextEntry::make('user.'.Debug::userNameAttribute())
                                    ->label('User')
                                    ->placeholder('—'),
                                TextEntry::make('ip')
                                    ->label('IP address')
                                    ->placeholder('—'),
                                TextEntry::make('user_agent')
                                    ->label('User agent')
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Tab::make('Context')
                            ->schema([
                                KeyValueEntry::make('context')
                                    ->hiddenLabel()
                                    ->placeholder('—'),
                            ])
                            ->columns(1)
                            ->visible(fn (Exception $record): bool => filled($record->context)),
                        Tab::make('Stack trace')
                            ->schema([
                                self::payloadEntry('trace')->hiddenLabel(),
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
                        ->modalHeading(fn (Exception $record): string => class_basename($record->exception_class))
                        ->modalDescription(fn (Exception $record): string => $record->message)
                        ->modalWidth(Width::FiveExtraLarge)),
                TextColumn::make('exception_class')
                    ->label('Exception')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->tooltip(fn (Exception $record): string => $record->exception_class)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('message')
                    ->label('Message')
                    ->limit(80)
                    ->description(fn (Exception $record): ?string => filled($record->file)
                        ? class_basename((string) $record->file).':'.$record->line
                        : null)
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
                TextColumn::make('user.'.Debug::userNameAttribute())
                    ->label('User')
                    ->placeholder('—')
                    ->searchable(Debug::userSearchColumns())
                    ->toggleable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->filters([
                SelectFilter::make('exception_class')
                    ->label('Exception')
                    ->options(fn (): array => Exception::query()
                        ->select('exception_class')
                        ->distinct()
                        ->orderBy('exception_class')
                        ->pluck('exception_class')
                        ->mapWithKeys(fn (string $class): array => [$class => class_basename($class)])
                        ->all())
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        Exception::SOURCE_HTTP => 'HTTP',
                        Exception::SOURCE_CONSOLE => 'Console',
                    ]),
                SelectFilter::make('fingerprint')
                    ->label('Problem')
                    ->options(fn (): array => Exception::query()
                        ->select(['fingerprint', 'exception_class', 'file', 'line'])
                        ->orderByDesc('occurred_at')
                        ->get()
                        ->unique('fingerprint')
                        ->mapWithKeys(fn (Exception $exception): array => [
                            $exception->fingerprint => class_basename($exception->exception_class)
                                .' — '.class_basename((string) $exception->file).':'.$exception->line,
                        ])
                        ->all())
                    ->searchable(),
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
            'index' => Pages\ListExceptions::route('/'),
            'view' => Pages\ViewException::route('/{record}'),
        ];
    }
}
