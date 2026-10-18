<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Invoice;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn (?Money $state) => $state?->format();
        $minor = fn ($state) => Money::of((int) $state)->format();

        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('number')
                            ->placeholder('Draft'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('customer.name')
                            ->label('Customer'),
                        TextEntry::make('journalEntry.reference')
                            ->label('Journal entry')
                            ->placeholder('-'),
                        TextEntry::make('issue_date')
                            ->date()
                            ->placeholder('-'),
                        TextEntry::make('due_date')
                            ->date()
                            ->placeholder('-'),
                        TextEntry::make('notes')
                            ->placeholder('-')
                            ->columnSpan(2),
                    ]),
                Section::make('Amounts')
                    ->columns(5)
                    ->schema([
                        TextEntry::make('subtotal')->formatStateUsing($money),
                        TextEntry::make('tax_total')->label('VAT')->formatStateUsing($money),
                        TextEntry::make('total')->formatStateUsing($money)->weight('bold'),
                        TextEntry::make('amount_paid')->formatStateUsing($money),
                        TextEntry::make('outstanding')
                            ->state(fn (Invoice $record) => $record->outstanding()->format())
                            ->weight('bold'),
                    ]),
                Section::make('Lines')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Description'),
                                TableColumn::make('Qty'),
                                TableColumn::make('Unit price'),
                                TableColumn::make('VAT'),
                                TableColumn::make('Net amount'),
                            ])
                            ->schema([
                                TextEntry::make('description'),
                                TextEntry::make('quantity'),
                                TextEntry::make('unit_price')->formatStateUsing($minor),
                                TextEntry::make('line_tax')->formatStateUsing($minor),
                                TextEntry::make('line_subtotal')->formatStateUsing($minor),
                            ]),
                    ]),
                Section::make('Payments')
                    ->visible(fn (Invoice $record) => $record->payments()->exists())
                    ->schema([
                        RepeatableEntry::make('payments')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Date'),
                                TableColumn::make('Amount'),
                                TableColumn::make('Method'),
                                TableColumn::make('Reference'),
                                TableColumn::make('Bank match'),
                            ])
                            ->schema([
                                TextEntry::make('paid_on')->date(),
                                TextEntry::make('amount')->formatStateUsing($money),
                                TextEntry::make('method'),
                                TextEntry::make('reference')->placeholder('-'),
                                TextEntry::make('bankTransaction.booked_on')
                                    ->date()
                                    ->placeholder('Not reconciled'),
                            ]),
                    ]),
            ]);
    }
}
