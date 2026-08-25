<?php

namespace Backstage\Debug\Filament\Resources;

use BackedEnum;
use Backstage\Debug\Debug;
use Backstage\Debug\Enums\ExceptionStatus;
use Backstage\Debug\Filament\Clusters\DebugCluster;
use Backstage\Debug\Filament\Concerns\FormatsDebugPayloads;
use Backstage\Debug\Filament\Resources\ExceptionResource\Pages;
use Backstage\Debug\Models\Exception;
use Backstage\Debug\Models\ExceptionState;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
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
use Illuminate\Database\Eloquent\Collection;

class ExceptionResource extends Resource
{
    use FormatsDebugPayloads;

    /**
     * The two values of the status filter that are not a status: what nobody
     * has put away yet, and no narrowing at all.
     */
    protected const OPEN = 'open';

    protected const EVERYTHING = 'all';

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

    /**
     * What went wrong in the last day and nobody has put away yet. A badge that
     * counted the ignored problems too would keep asking for attention that has
     * already been given.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Exception::query()
            ->open()
            ->where('occurred_at', '>=', now()->subDay())
            ->count();

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
                                TextEntry::make('state.status')
                                    ->label('Status')
                                    ->badge()
                                    ->placeholder('Open')
                                    ->formatStateUsing(fn (ExceptionStatus $state): string => $state->label())
                                    ->color(fn (ExceptionStatus $state): string => $state->color())
                                    ->helperText(fn (Exception $record): ?string => self::decisionSummary($record)),
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
                        ->modalWidth(Width::FiveExtraLarge)
                        // Deciding what to do with a problem is what you are
                        // there for once you have read it, so the decision sits
                        // in the modal rather than a page further on.
                        ->extraModalFooterActions(fn (): array => self::decisionActions(closesModal: true))),
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
                // Off by default: the table opens on what nobody has put away
                // yet, where every row would read the same.
                TextColumn::make('state.status')
                    ->label('Status')
                    ->badge()
                    ->placeholder('Open')
                    ->formatStateUsing(fn (ExceptionStatus $state): string => $state->label())
                    ->color(fn (ExceptionStatus $state): string => $state->color())
                    ->description(fn (Exception $record): ?string => $record->status() === ExceptionStatus::Fixed && ! $record->isPutAway()
                        ? 'came back'
                        : null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'state']))
            ->filters([
                self::statusFilter(),
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
                    ...self::decisionBulkActions(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Which problems the table shows. It opens on the ones nobody has put away
     * yet, and the rest is a filter away rather than gone: ignoring a problem
     * hides it, it does not stop it being recorded.
     */
    protected static function statusFilter(): SelectFilter
    {
        return SelectFilter::make('status')
            ->label('Status')
            ->options([
                self::OPEN => 'Open',
                ExceptionStatus::Ignored->value => 'Ignored',
                ExceptionStatus::Fixed->value => 'Fixed',
                self::EVERYTHING => 'Everything',
            ])
            ->default(self::OPEN)
            ->selectablePlaceholder(false)
            ->query(fn (Builder $query, array $data): Builder => self::narrowToStatus($query, $data['value'] ?? null));
    }

    /**
     * @param  Builder<Exception>  $query
     * @return Builder<Exception>
     */
    protected static function narrowToStatus(Builder $query, ?string $value): Builder
    {
        return match ($value ?? self::OPEN) {
            ExceptionStatus::Ignored->value => $query->inState(ExceptionStatus::Ignored),
            ExceptionStatus::Fixed->value => $query->inState(ExceptionStatus::Fixed),
            self::EVERYTHING => $query,
            default => $query->open(),
        };
    }

    /**
     * What can be decided about the problem behind a single exception. The same
     * three answers are offered from the modal and from the record's own page,
     * so it never matters which way in you took.
     *
     * @return array<int, Action>
     */
    public static function decisionActions(bool $closesModal = false): array
    {
        return [
            Action::make('ignore')
                ->label('Ignore')
                ->icon(ExceptionStatus::Ignored->icon())
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Ignore this problem?')
                ->modalDescription('It keeps being recorded, it just stays out of the table until you ask for it.')
                ->visible(fn (Exception $record): bool => $record->status() !== ExceptionStatus::Ignored)
                ->action(fn (Exception $record) => $record->ignore())
                ->successNotificationTitle('Ignored')
                ->cancelParentActions($closesModal),
            Action::make('markFixed')
                ->label(fn (Exception $record): string => $record->status() === ExceptionStatus::Fixed
                    ? 'Mark as fixed again'
                    : 'Mark as fixed')
                ->icon(ExceptionStatus::Fixed->icon())
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Mark this problem as fixed?')
                ->modalDescription('Everything up to now goes out of the table. If it happens again, it comes back on its own.')
                ->visible(fn (Exception $record): bool => ! ($record->status() === ExceptionStatus::Fixed && $record->isPutAway()))
                ->action(fn (Exception $record) => $record->markFixed())
                ->successNotificationTitle('Marked as fixed')
                ->cancelParentActions($closesModal),
            Action::make('reopen')
                ->label('Reopen')
                ->icon('lucide-undo-2')
                ->color('warning')
                ->visible(fn (Exception $record): bool => $record->status() !== null)
                ->action(fn (Exception $record) => $record->reopen())
                ->successNotificationTitle('Reopened')
                ->cancelParentActions($closesModal),
        ];
    }

    /**
     * The same three answers for a selection. They are given per problem rather
     * than per row: picking two occurrences of one failure and one of another
     * decides about two problems, not three.
     *
     * @return array<int, BulkAction>
     */
    public static function decisionBulkActions(): array
    {
        return [
            BulkAction::make('ignore')
                ->label('Ignore')
                ->icon(ExceptionStatus::Ignored->icon())
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('They keep being recorded, they just stay out of the table until you ask for them.')
                ->successNotificationTitle('Ignored')
                ->accessSelectedRecords()
                ->action(fn (Collection $records) => self::decideOnSelection($records, ExceptionStatus::Ignored))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('markFixed')
                ->label('Mark as fixed')
                ->icon(ExceptionStatus::Fixed->icon())
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Everything up to now goes out of the table. What happens again comes back on its own.')
                ->successNotificationTitle('Marked as fixed')
                ->accessSelectedRecords()
                ->action(fn (Collection $records) => self::decideOnSelection($records, ExceptionStatus::Fixed))
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('reopen')
                ->label('Reopen')
                ->icon('lucide-undo-2')
                ->color('warning')
                ->requiresConfirmation()
                ->successNotificationTitle('Reopened')
                ->accessSelectedRecords()
                ->action(fn (Collection $records) => self::decideOnSelection($records, status: null))
                ->deselectRecordsAfterCompletion(),
        ];
    }

    /**
     * Apply one decision to every problem in the selection, or take the
     * decision back when there is none to apply.
     *
     * @param  Collection<int, Exception>  $records
     */
    protected static function decideOnSelection(Collection $records, ?ExceptionStatus $status): void
    {
        $records->pluck('fingerprint')
            ->unique()
            ->each(fn (string $fingerprint) => $status === null
                ? ExceptionState::clear($fingerprint)
                : ExceptionState::mark($fingerprint, $status));
    }

    /**
     * Who decided what, and when — the part of a status that a badge cannot
     * carry.
     */
    protected static function decisionSummary(Exception $record): ?string
    {
        $state = $record->state;

        if ($state === null) {
            return null;
        }

        $who = $state->user?->{Debug::userNameAttribute()};
        $when = $state->marked_at->format('d M Y H:i');

        return filled($who)
            ? "{$state->status->label()} by {$who} on {$when}."
            : "{$state->status->label()} on {$when}.";
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExceptions::route('/'),
            'view' => Pages\ViewException::route('/{record}'),
        ];
    }
}
