<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Enums\AccountType;
use App\Models\Account;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Load the debit/credit totals in the list query instead of one query per row.
            ->modifyQueryUsing(fn ($query) => $query->withSum('lines', 'debit')->withSum('lines', 'credit'))
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('balance')
                    ->state(fn (Account $record) => $record
                        ->normalBalance((int) $record->lines_sum_debit, (int) $record->lines_sum_credit)
                        ->format())
                    ->alignEnd(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(AccountType::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
