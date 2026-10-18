<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Enums\AccountType;
use App\Models\Account;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        $hasPostings = fn (?Account $record) => $record?->lines()->exists() ?? false;

        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true)
                    ->disabled($hasPostings),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options(AccountType::class)
                    ->required()
                    ->disabled($hasPostings),
                Toggle::make('is_active')
                    ->label('Active')
                    ->helperText('Inactive accounts cannot receive new postings.')
                    ->default(true),
            ]);
    }
}
