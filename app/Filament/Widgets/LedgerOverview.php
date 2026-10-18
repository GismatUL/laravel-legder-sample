<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Invoice;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LedgerOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $accounts = config('ledger.accounts');

        $overdue = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid])
            ->whereDate('due_date', '<', today())
            ->count();

        return [
            Stat::make('Bank balance', Account::byCode($accounts['bank'])->balance()->format()),
            Stat::make('Receivables', Account::byCode($accounts['receivable'])->balance()->format())
                ->description('Read from the ledger, not from invoice rows'),
            Stat::make('Overdue invoices', (string) $overdue)
                ->color($overdue > 0 ? 'danger' : 'success'),
            Stat::make('Unreconciled bank lines', (string) BankTransaction::unreconciled()->count()),
        ];
    }
}
