<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Rules\MoneyAmount;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Invoice')
                    ->columns(2)
                    ->schema([
                        Select::make('customer_id')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        DatePicker::make('due_date')
                            ->helperText('Leave empty to use the default payment terms.'),
                        Textarea::make('notes')
                            ->columnSpanFull(),
                    ]),
                Section::make('Lines')
                    ->description('Totals and VAT are calculated on save, from the values below.')
                    ->schema([
                        // Not a relationship repeater: lines are saved by InvoiceService, the same as the API.
                        Repeater::make('lines')
                            ->hiddenLabel()
                            ->columns(12)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->schema([
                                TextInput::make('description')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(6),
                                TextInput::make('quantity')
                                    ->required()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->columnSpan(2),
                                TextInput::make('unit_price')
                                    ->required()
                                    ->rule(new MoneyAmount)
                                    ->columnSpan(2),
                                Select::make('tax_rate_bp')
                                    ->label('VAT')
                                    ->options([0 => '0%', 1800 => '18%'])
                                    ->default(1800)
                                    ->required()
                                    ->columnSpan(2),
                            ]),
                    ]),
            ]);
    }
}
