<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceActions;
use App\Models\Invoice;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        $money = fn (?Money $state) => $state?->format();

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->placeholder('Draft')
                    ->searchable(),
                TextColumn::make('customer.name')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('issue_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->date()
                    ->sortable()
                    ->color(fn (Invoice $record) => $record->status->acceptsPayments() && $record->due_date?->isPast() ? 'danger' : null),
                TextColumn::make('total')
                    ->formatStateUsing($money)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('outstanding')
                    ->state(fn (Invoice $record) => $record->outstanding()->format())
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InvoiceStatus::class),
                Filter::make('overdue')
                    ->query(fn (Builder $query) => $query
                        ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid])
                        ->whereDate('due_date', '<', today())),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    EditAction::make(),
                    InvoiceActions::issue(),
                    InvoiceActions::recordPayment(),
                    InvoiceActions::void(),
                ]),
            ]);
    }
}
