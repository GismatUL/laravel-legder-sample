<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Exceptions\BankingException;
use App\Filament\Pages\MatchBankLines;
use App\Models\ActivityLog;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\BankReconciler;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Money;
use App\Support\TokenAbility;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class ManualMatchingTest extends LedgerTestCase
{
    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $invoice = app(InvoiceService::class)->issue(Invoice::factory()->withLines([[1, 10000, 1800]])->create());
        $this->payment = app(PaymentService::class)->record($invoice, Money::parse('118.00'), today(), PaymentMethod::BankTransfer, 'p-1');
    }

    /** A line the automatic run can't match: no invoice number in the reference. */
    private function bankLine(string $amount = '118.00', string $reference = 'TRANSFER FROM ACME LLC'): BankTransaction
    {
        $transaction = new BankTransaction;
        $transaction->forceFill([
            'booked_on' => today(),
            'amount' => Money::parse($amount),
            'reference' => $reference,
            'fingerprint' => hash('sha256', $reference.$amount.uniqid()),
        ])->save();

        return $transaction;
    }

    public function test_an_accountant_matches_a_line_on_the_page(): void
    {
        $user = User::factory()->role(UserRole::Accountant)->create();
        $this->actingAs($user);
        $line = $this->bankLine();
        $this->bankLine('5.00', 'Interest'); // other amount: not a candidate

        Livewire::test(MatchBankLines::class)
            ->assertSee('TRANSFER FROM ACME LLC')
            ->call('select', $line->id)
            ->assertSee($this->payment->invoice->number)
            ->call('match', $this->payment->id)
            ->assertNotified()
            ->assertSet('selectedId', null)
            ->assertDontSee('TRANSFER FROM ACME LLC');

        $line->refresh();
        $this->assertSame($this->payment->id, $line->payment_id);
        $this->assertSame('manual', $line->match_method);
        $this->assertSame($user->id, $line->matched_by);
        $this->assertNotNull($line->reconciled_at);

        $log = ActivityLog::query()->where('event', 'matched')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($this->payment->invoice->number, $log->changes['invoice_number']);
    }

    public function test_only_payments_with_the_same_amount_are_offered(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Accountant)->create());
        $line = $this->bankLine('50.00');

        Livewire::test(MatchBankLines::class)
            ->call('select', $line->id)
            ->assertDontSee($this->payment->invoice->number)
            ->assertSee('No unmatched payment of 50.00 AZN');
    }

    public function test_a_viewer_cannot_open_the_page_or_call_match(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Viewer)->create());
        $line = $this->bankLine();

        $this->get(MatchBankLines::getUrl())->assertForbidden();

        $this->assertThrows(fn () => app(MatchBankLines::class)->match($this->payment->id));
        $this->assertNull($line->refresh()->payment_id);
    }

    public function test_a_business_rule_error_is_shown_and_nothing_changes(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Accountant)->create());
        $first = $this->bankLine();
        $second = $this->bankLine(reference: 'ACME AGAIN');
        app(BankReconciler::class)->matchManually($first, $this->payment);

        Livewire::test(MatchBankLines::class)
            ->call('select', $second->id)
            ->call('match', $this->payment->id)
            ->assertNotified('This payment is already matched to another bank line.');

        $this->assertNull($second->refresh()->payment_id);
    }

    /** @return array<string, array{callable(self): array{BankTransaction, Payment}, string}> */
    public static function invalidMatches(): array
    {
        return [
            'different amount' => [fn (self $t) => [$t->bankLine('117.99'), $t->payment], 'banking.amount_mismatch'],
            'outgoing line' => [fn (self $t) => [$t->bankLine('-118.00'), $t->payment], 'banking.not_incoming'],
            'line already reconciled' => [function (self $t) {
                $line = $t->bankLine();
                $line->forceFill(['reconciled_at' => now()])->save();

                return [$line, $t->payment];
            }, 'banking.already_reconciled'],
        ];
    }

    /**
     * @param  callable(self): array{BankTransaction, Payment}  $arrange
     */
    #[DataProvider('invalidMatches')]
    public function test_the_service_refuses_invalid_matches(callable $arrange, string $code): void
    {
        [$line, $payment] = $arrange($this);

        try {
            app(BankReconciler::class)->matchManually($line, $payment);
            $this->fail('Invalid match was accepted.');
        } catch (BankingException $e) {
            $this->assertSame($code, $e->errorCode);
        }

        $this->assertSame(0, ActivityLog::query()->where('event', 'matched')->count());
    }

    public function test_the_automatic_run_records_its_matches_as_auto(): void
    {
        $line = $this->bankLine(reference: "Payment {$this->payment->invoice->number}");

        app(BankReconciler::class)->reconcile($line);

        $line->refresh();
        $this->assertSame('auto', $line->match_method);
        $this->assertNull($line->matched_by);
    }

    public function test_matching_through_the_api(): void
    {
        $line = $this->bankLine();
        Sanctum::actingAs(User::factory()->role(UserRole::Accountant)->create(), TokenAbility::all());

        $this->postJson("/api/v1/bank-transactions/{$line->id}/match", ['payment_id' => $this->payment->id])
            ->assertOk()
            ->assertJsonPath('data.payment_id', $this->payment->id)
            ->assertJsonPath('data.match_method', 'manual')
            ->assertJsonPath('data.invoice_number', $this->payment->invoice->number);

        $this->postJson("/api/v1/bank-transactions/{$line->id}/match", ['payment_id' => $this->payment->id])
            ->assertConflict()
            ->assertJsonPath('code', 'banking.already_reconciled');
    }

    public function test_a_viewer_cannot_match_through_the_api(): void
    {
        $line = $this->bankLine();
        Sanctum::actingAs(User::factory()->role(UserRole::Viewer)->create(), TokenAbility::all());

        $this->postJson("/api/v1/bank-transactions/{$line->id}/match", ['payment_id' => $this->payment->id])->assertForbidden();
    }
}
