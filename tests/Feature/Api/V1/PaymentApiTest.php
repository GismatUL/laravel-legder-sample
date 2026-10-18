<?php

namespace Tests\Feature\Api\V1;

use App\Models\Invoice;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

class PaymentApiTest extends ApiTestCase
{
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsApiUser();
        $this->invoice = $this->issuedInvoice();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function pay(string $key, array $overrides = []): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson(
            "/api/v1/invoices/{$this->invoice->id}/payments",
            array_merge(['amount' => '50.00', 'paid_on' => today()->toDateString(), 'method' => 'bank_transfer'], $overrides),
        );
    }

    public function test_records_a_payment(): void
    {
        $this->pay('psp-tx-1', ['reference' => 'Transfer 991'])
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.amount', ['amount' => '50.00', 'currency' => 'AZN'])
            ->assertJsonPath('data.reference', 'Transfer 991');

        $this->getJson("/api/v1/invoices/{$this->invoice->id}")
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.outstanding.amount', '68.00')
            ->assertJsonCount(1, 'data.payments');
    }

    public function test_a_retry_with_the_same_key_returns_the_same_payment(): void
    {
        $first = $this->pay('psp-tx-1')->assertCreated()->json('data.id');

        $this->pay('psp-tx-1')
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first);

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('50.00', $this->balance('bank')->toDecimal());
    }

    public function test_the_same_key_with_a_different_amount_is_a_conflict(): void
    {
        $this->pay('psp-tx-1')->assertCreated();

        $this->pay('psp-tx-1', ['amount' => '60.00'])
            ->assertConflict()
            ->assertJsonPath('code', 'billing.idempotency_conflict');
    }

    public function test_the_idempotency_key_header_is_required(): void
    {
        $this->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => '50.00', 'paid_on' => today()->toDateString(), 'method' => 'cash',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_overpayment_is_a_business_rule_error(): void
    {
        $this->pay('psp-tx-1', ['amount' => '118.01'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Payment of 118.01 AZN exceeds the outstanding balance of 118.00 AZN.',
                'code' => 'billing.overpayment',
            ]);
    }

    public function test_future_dated_payments_are_rejected(): void
    {
        $this->pay('psp-tx-1', ['paid_on' => today()->addDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paid_on');
    }

    public function test_lists_payments_of_an_invoice(): void
    {
        $this->pay('a', ['amount' => '18.00']);
        $this->pay('b', ['amount' => '100.00']);

        $this->getJson("/api/v1/invoices/{$this->invoice->id}/payments")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reconciled', false);

        $this->getJson("/api/v1/invoices/{$this->invoice->id}")->assertJsonPath('data.status', 'paid');
    }
}
