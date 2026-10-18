<?php

namespace Tests\Feature\Api\V1;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Support\TokenAbility;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\LedgerTestCase;

abstract class ApiTestCase extends LedgerTestCase
{
    /**
     * @param  list<string>|null  $abilities
     */
    protected function actingAsApiUser(?array $abilities = null): User
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, $abilities ?? TokenAbility::all());

        return $user;
    }

    /**
     * An issued invoice: 100.00 net + 18% VAT = 118.00.
     */
    protected function issuedInvoice(): Invoice
    {
        return app(InvoiceService::class)->issue(
            Invoice::factory()->withLines([[1, 10000, 1800]])->create(),
        );
    }
}
