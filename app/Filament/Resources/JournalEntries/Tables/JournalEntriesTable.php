<?php

namespace App\Filament\Resources\JournalEntries\Tables;

use App\Models\JournalEntry;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JournalEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('lines', 'debit'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->state(fn (JournalEntry $record) => $record->reference),
                TextColumn::make('entry_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('lines_sum_debit')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => Money::of((int) $state)->format())
                    ->alignEnd(),
                TextColumn::make('reversal_of_id')
                    ->label('Type')
                    ->badge()
                    ->state(fn (JournalEntry $record) => $record->reversal_of_id ? 'Reversal' : ($record->source_type ? 'Document' : 'Manual'))
                    ->color(fn (string $state) => match ($state) {
                        'Reversal' => 'danger',
                        'Manual' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Filter::make('manual')
                    ->label('Manual entries only')
                    ->query(fn (Builder $query) => $query->whereNull('source_type')),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
