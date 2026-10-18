<?php

namespace App\Filament\Resources\JournalEntries\Schemas;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JournalEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $amount = fn ($state) => (int) $state === 0 ? '' : Money::of((int) $state)->format();

        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('reference'),
                        TextEntry::make('entry_date')->date(),
                        TextEntry::make('description')->columnSpan(2),
                        TextEntry::make('source')
                            ->state(fn (JournalEntry $record) => $record->source instanceof Invoice
                                ? 'Invoice '.$record->source->number
                                : 'Manual entry'),
                        TextEntry::make('reversalOf.reference')
                            ->label('Reverses')
                            ->placeholder('-'),
                        TextEntry::make('reversedBy.reference')
                            ->label('Reversed by')
                            ->placeholder('-'),
                        TextEntry::make('posted_at')->dateTime(),
                        TextEntry::make('postedBy.name')
                            ->label('Posted by')
                            ->placeholder('System'),
                    ]),
                Section::make('Lines')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Account'),
                                TableColumn::make('Debit'),
                                TableColumn::make('Credit'),
                                TableColumn::make('Memo'),
                            ])
                            ->schema([
                                TextEntry::make('account.name')
                                    ->formatStateUsing(fn ($state, $record) => "{$record->account->code} {$state}"),
                                TextEntry::make('debit')->formatStateUsing($amount),
                                TextEntry::make('credit')->formatStateUsing($amount),
                                TextEntry::make('memo')->placeholder('-'),
                            ]),
                    ]),
            ]);
    }
}
