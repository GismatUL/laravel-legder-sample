<?php

namespace App\Filament\Resources\JournalEntries\Schemas;

use App\Models\Account;
use App\Repositories\Contracts\AccountRepositoryInterface;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Manual journal entry (e.g. an expense paid from the bank, an owner
 * contribution). The balance check happens in LedgerService, not here,
 * so the UI can't post anything the service would refuse.
 */
class JournalEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        $amount = fn (string $name) => TextInput::make($name)
            ->regex('/^\d+(\.\d{1,2})?$/')
            ->validationMessages(['regex' => 'Use a positive amount with up to 2 decimals.'])
            ->placeholder('0.00')
            ->columnSpan(2);

        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        DatePicker::make('entry_date')
                            ->required()
                            ->default(today()),
                        TextInput::make('description')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                    ]),
                Section::make('Lines')
                    ->description('Each line is either a debit or a credit. Total debits must equal total credits.')
                    ->schema([
                        Repeater::make('lines')
                            ->hiddenLabel()
                            ->columns(12)
                            ->minItems(2)
                            ->defaultItems(2)
                            ->schema([
                                Select::make('account_id')
                                    ->label('Account')
                                    ->options(fn () => app(AccountRepositoryInterface::class)
                                        ->active()
                                        ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} {$a->name}"]))
                                    ->searchable()
                                    ->required()
                                    ->columnSpan(4),
                                $amount('debit'),
                                $amount('credit'),
                                TextInput::make('memo')
                                    ->maxLength(255)
                                    ->columnSpan(4),
                            ]),
                    ]),
            ]);
    }
}
