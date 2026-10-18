<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only audit trail. Ledger postings are not here: journal entries are
 * immutable and record who posted them (posted_by).
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'Activity log';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->label('When'),
                TextColumn::make('user.name')->label('User')->placeholder('System'),
                TextColumn::make('event')->badge(),
                TextColumn::make('subject_type')->label('Record')
                    ->formatStateUsing(fn (string $state, ActivityLog $record) => str_replace('_', ' ', ucfirst($state))." #{$record->subject_id}"),
                TextColumn::make('changes')
                    ->state(fn (ActivityLog $record) => self::describe($record->changes ?? []))
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('subject_type')->label('Record type')
                    ->options(['account' => 'Account', 'customer' => 'Customer', 'bank_transaction' => 'Bank transaction']),
                SelectFilter::make('event')
                    ->options(['created' => 'Created', 'updated' => 'Updated', 'matched' => 'Matched']),
            ]);
    }

    /**
     * "name: Bank → Main bank, is_active: 1 → 0" for updates, "field: value" otherwise.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function describe(array $changes): string
    {
        return collect($changes)
            ->map(fn ($value, string $field) => is_array($value) && array_key_exists('old', $value)
                ? "{$field}: ".json_encode($value['old']).' → '.json_encode($value['new'])
                : "{$field}: ".json_encode($value))
            ->implode(', ');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
        ];
    }
}
