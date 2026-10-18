<?php

namespace Tests\Unit;

use App\Enums\InvoiceStatus;
use PHPUnit\Framework\TestCase;

class InvoiceStatusTest extends TestCase
{
    /**
     * Every pair of states, so adding a state or a transition shows up here.
     */
    public function test_transition_matrix(): void
    {
        $allowed = [
            'draft' => ['issued'],
            'issued' => ['partially_paid', 'paid', 'void'],
            'partially_paid' => ['partially_paid', 'paid'],
            'paid' => [],
            'void' => [],
        ];

        foreach (InvoiceStatus::cases() as $from) {
            foreach (InvoiceStatus::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $allowed[$from->value], true),
                    $from->canTransitionTo($to),
                    "{$from->value} -> {$to->value}",
                );
            }
        }
    }

    public function test_only_open_invoices_accept_payments(): void
    {
        $accepting = array_filter(InvoiceStatus::cases(), fn (InvoiceStatus $s) => $s->acceptsPayments());

        $this->assertEqualsCanonicalizing([InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], array_values($accepting));
    }
}
