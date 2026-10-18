<?php

namespace App\Filament\Resources\BankTransactions\Tables;

use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BankTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('payment.invoice'))
            ->defaultSort('booked_on', 'desc')
            ->columns([
                TextColumn::make('booked_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('reference')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (Money $state) => $state->format())
                    ->color(fn (Money $state) => $state->isNegative() ? 'danger' : 'success')
                    ->alignEnd(),
                TextColumn::make('payment.invoice.number')
                    ->label('Matched invoice')
                    ->placeholder('Unmatched'),
                TextColumn::make('match_method')
                    ->label('Matched')
                    ->badge()
                    ->color(fn (string $state) => $state === 'manual' ? 'warning' : 'gray')
                    ->placeholder('-'),
                TextColumn::make('reconciled_at')
                    ->dateTime()
                    ->placeholder('-'),
            ])
            ->filters([
                TernaryFilter::make('reconciled')
                    ->nullable()
                    ->attribute('reconciled_at')
                    ->default(false),
            ]);
    }
}
