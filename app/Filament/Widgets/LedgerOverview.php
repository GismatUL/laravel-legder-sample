<?php

namespace App\Filament\Widgets;

use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Repositories\Contracts\BankTransactionRepositoryInterface;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Services\ChartOfAccounts;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LedgerOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $chart = app(ChartOfAccounts::class);
        $accounts = app(AccountRepositoryInterface::class);
        $overdue = app(InvoiceRepositoryInterface::class)->countOverdue();

        return [
            Stat::make('Bank balance', $accounts->balance($chart->bank())->format()),
            Stat::make('Receivables', $accounts->balance($chart->receivable())->format())
                ->description('Read from the ledger, not from invoice rows'),
            Stat::make('Overdue invoices', (string) $overdue)
                ->color($overdue > 0 ? 'danger' : 'success'),
            Stat::make('Unreconciled bank lines', (string) app(BankTransactionRepositoryInterface::class)->countUnreconciled()),
        ];
    }
}
