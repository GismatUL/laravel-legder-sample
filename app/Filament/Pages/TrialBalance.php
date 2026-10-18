<?php

namespace App\Filament\Pages;

use App\Reports\TrialBalance as TrialBalanceReport;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

class TrialBalance extends Page
{
    protected string $view = 'filament.pages.trial-balance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 3;

    public ?string $asOf = null;

    public function mount(): void
    {
        $this->asOf = today()->toDateString();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('asOf')
                ->label('As of')
                ->live(),
        ]);
    }

    public function getReport(): TrialBalanceReport
    {
        return new TrialBalanceReport($this->asOf ? Carbon::parse($this->asOf) : null);
    }
}
