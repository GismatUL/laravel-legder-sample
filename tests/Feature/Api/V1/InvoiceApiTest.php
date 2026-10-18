<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceApiTest extends ApiTestCase
{
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsApiUser();
        $this->customer = Customer::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'due_date' => today()->addDays(30)->toDateString(),
            'lines' => [
                ['description' => 'Consulting', 'quantity' => 4, 'unit_price' => '125.50', 'tax_rate_bp' => 1800],
                ['description' => 'Travel', 'quantity' => 1, 'unit_price' => '40.00', 'tax_rate_bp' => 0],
            ],
        ], $overrides);
    }

    public function test_creates_a_draft_with_server_calculated_totals(): void
    {
        $this->postJson('/api/v1/invoices', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.subtotal', ['amount' => '542.00', 'currency' => 'AZN'])
            ->assertJsonPath('data.tax_total.amount', '90.36')
            ->assertJsonPath('data.total.amount', '632.36')
            ->assertJsonPath('data.outstanding.amount', '632.36')
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.net_amount.amount', '502.00');
    }

    public function test_rejects_float_amounts_and_unknown_vat_rates(): void
    {
        $this->postJson('/api/v1/invoices', $this->payload([
            'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 12.345, 'tax_rate_bp' => 1700]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.unit_price', 'lines.0.tax_rate_bp']);
    }

    public function test_a_line_total_that_overflows_is_a_422_not_a_500(): void
    {
        // Each field is within its limit; the product isn't representable.
        $this->postJson('/api/v1/invoices', $this->payload([
            'lines' => [['description' => 'X', 'quantity' => 1000000, 'unit_price' => '9999999999999.99', 'tax_rate_bp' => 0]],
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'money.overflow');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_full_lifecycle_issue_then_void(): void
    {
        $id = $this->postJson('/api/v1/invoices', $this->payload())->json('data.id');

        $this->postJson("/api/v1/invoices/{$id}/issue")
            ->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.number', 'INV-000001')
            ->assertJsonPath('data.issue_date', today()->toDateString());

        $this->postJson("/api/v1/invoices/{$id}/issue")
            ->assertConflict()
            ->assertJsonPath('code', 'billing.invalid_transition');

        $this->postJson("/api/v1/invoices/{$id}/void")
            ->assertOk()
            ->assertJsonPath('data.status', 'void');

        $this->assertTrue($this->balance('receivable')->isZero());
    }

    public function test_only_drafts_can_be_updated_or_deleted(): void
    {
        $draft = $this->postJson('/api/v1/invoices', $this->payload())->json('data.id');

        $this->putJson("/api/v1/invoices/{$draft}", $this->payload([
            'lines' => [['description' => 'Only line', 'quantity' => 1, 'unit_price' => '10.00', 'tax_rate_bp' => 0]],
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.total.amount', '10.00');

        $issued = $this->issuedInvoice();

        $this->putJson("/api/v1/invoices/{$issued->id}", $this->payload())->assertConflict();
        $this->deleteJson("/api/v1/invoices/{$issued->id}")->assertConflict();

        $this->deleteJson("/api/v1/invoices/{$draft}")->assertNoContent();
        $this->assertDatabaseMissing('invoices', ['id' => $draft]);
    }

    public function test_filters_by_status_and_overdue(): void
    {
        $overdue = $this->issuedInvoice();
        $overdue->forceFill(['due_date' => today()->subDay()])->save();
        $this->issuedInvoice();
        Invoice::factory()->withLines([[1, 1000]])->create();

        $this->getJson('/api/v1/invoices?status=issued')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/invoices?status=draft')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/invoices?overdue=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue->id);
    }

    public function test_list_query_count_does_not_grow_with_rows(): void
    {
        Invoice::factory(3)->withLines([[1, 1000]])->create();
        $few = $this->countQueries(fn () => $this->getJson('/api/v1/invoices')->assertOk());

        Invoice::factory(20)->withLines([[1, 1000]])->create();
        $many = $this->countQueries(fn () => $this->getJson('/api/v1/invoices')->assertOk());

        $this->assertSame($few, $many);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
