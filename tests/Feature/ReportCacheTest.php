<?php

namespace Tests\Feature;

use App\Data\ManualEntryLineData;
use App\Enums\AccountType;
use App\Events\LedgerPosted;
use App\Reports\ReportCache;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Services\AccountService;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Support\Money;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class ReportCacheTest extends LedgerTestCase
{
    private function postExpense(string $amount): void
    {
        app(LedgerService::class)->postManual(today(), 'Office supplies', [
            new ManualEntryLineData($this->accountByCode('6000')->id, Money::parse($amount), Money::zero()),
            new ManualEntryLineData($this->account('bank')->id, Money::zero(), Money::parse($amount)),
        ]);
    }

    private function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    public function test_a_repeated_trial_balance_is_served_from_the_cache(): void
    {
        $this->postExpense('50.00');
        $reports = app(ReportService::class);
        $reports->trialBalance();

        $this->assertSame(0, $this->queriesFor(fn () => $reports->trialBalance()));
        $this->assertTrue($reports->trialBalance()->totalDebit->equals(Money::parse('50.00')));
    }

    public function test_a_posting_invalidates_every_cached_date(): void
    {
        $reports = app(ReportService::class);
        $reports->trialBalance();
        $reports->trialBalance(today()->subYear());

        $this->postExpense('50.00');

        $this->assertTrue($reports->trialBalance()->totalDebit->equals(Money::parse('50.00')));
        $this->assertGreaterThan(0, $this->queriesFor(fn () => $reports->trialBalance(today()->subYear())));
    }

    public function test_a_rolled_back_posting_dispatches_nothing_and_keeps_the_cache(): void
    {
        $reports = app(ReportService::class);
        $reports->trialBalance();

        $this->assertThrows(fn () => DB::transaction(function () {
            $this->postExpense('50.00');

            throw new RuntimeException('Something later in the same transaction failed');
        }), RuntimeException::class);

        $this->assertSame(0, $this->queriesFor(fn () => $reports->trialBalance()));
        $this->assertTrue($reports->trialBalance()->totalDebit->isZero());
    }

    public function test_renaming_an_account_invalidates_the_cache(): void
    {
        $this->postExpense('50.00');
        $reports = app(ReportService::class);
        $reports->trialBalance();

        app(AccountService::class)->update($this->accountByCode('6000'), ['name' => 'Office costs']);

        $this->assertContains('Office costs', $reports->trialBalance()->rows->pluck('name')->all());
    }

    /**
     * Regression: Redis serializes values and the cache allows no classes
     * (cache.serializable_classes = false), so cached objects came back as
     * __PHP_Incomplete_Class. The array store used in tests didn't serialize.
     */
    public function test_works_with_a_store_that_serializes_and_allows_no_classes(): void
    {
        $this->postExpense('50.00');
        $store = new Repository(new ArrayStore(serializesValues: true, serializableClasses: false));
        $reports = new ReportService(app(JournalEntryRepositoryInterface::class), new ReportCache($store, 600));

        $reports->trialBalance();
        $cached = $reports->trialBalance();

        $this->assertTrue($cached->totalDebit->equals(Money::parse('50.00')));
        $this->assertSame(AccountType::Expense, $cached->rows->firstWhere('code', '6000')['type'] ?? null);
    }

    public function test_every_posting_dispatches_a_ledger_posted_event(): void
    {
        Event::fake([LedgerPosted::class]);

        $this->postExpense('50.00');

        Event::assertDispatchedTimes(LedgerPosted::class, 1);
        Event::assertDispatched(LedgerPosted::class, fn (LedgerPosted $event) => $event->entryDate === today()->toDateString());
    }
}
