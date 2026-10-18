<?php

namespace App\Filament\Pages;

use App\Exceptions\DomainRuleException;
use App\Models\BankTransaction;
use App\Models\Payment;
use App\Repositories\Contracts\BankTransactionRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\BankReconciler;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Manual reconciliation: pick an unmatched bank line, then one of the
 * payments with exactly that amount. The Livewire component holds the
 * selection; Alpine filters the candidates in the browser and asks for
 * confirmation. The match itself goes through BankReconciler::matchManually(),
 * the same code as the API endpoint.
 *
 * @property-read Collection<int, BankTransaction> $transactions Livewire computed property
 * @property-read BankTransaction|null $selected Livewire computed property
 * @property-read Collection<int, Payment> $candidates Livewire computed property
 */
class MatchBankLines extends Page
{
    protected string $view = 'filament.pages.match-bank-lines';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Match bank lines';

    protected static ?int $navigationSort = 5;

    public ?int $selectedId = null;

    public static function canAccess(): bool
    {
        return Gate::allows('reconcile', BankTransaction::class);
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    #[Computed]
    public function transactions(): Collection
    {
        return app(BankTransactionRepositoryInterface::class)->unreconciledIncoming(100);
    }

    #[Computed]
    public function selected(): ?BankTransaction
    {
        return $this->transactions->firstWhere('id', $this->selectedId);
    }

    /**
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function candidates(): Collection
    {
        return $this->selected === null
            ? collect()
            : app(PaymentRepositoryInterface::class)->unmatchedWithAmount($this->selected->amount, 50);
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        unset($this->selected, $this->candidates);
    }

    public function clearSelection(): void
    {
        $this->selectedId = null;
        unset($this->selected, $this->candidates);
    }

    public function match(int $paymentId): void
    {
        // Every Livewire call is a new request: check again, not only on page load.
        Gate::authorize('reconcile', BankTransaction::class);

        $transaction = $this->selected;

        if ($transaction === null) {
            return;
        }

        try {
            app(BankReconciler::class)->matchManually($transaction, app(PaymentRepositoryInterface::class)->find($paymentId));
        } catch (DomainRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title("Matched {$transaction->amount} from {$transaction->booked_on->toDateString()}")->send();

        $this->selectedId = null;
        unset($this->transactions, $this->selected, $this->candidates);
    }
}
