<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;

class CustomerApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsApiUser();
    }

    public function test_creates_shows_and_updates_a_customer(): void
    {
        $id = $this->postJson('/api/v1/customers', ['name' => 'Acme LLC', 'email' => 'billing@acme.test', 'tax_id' => '1234567890'])
            ->assertCreated()
            ->json('data.id');

        $this->getJson("/api/v1/customers/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme LLC')
            ->assertJsonPath('data.invoices_count', 0);

        $this->patchJson("/api/v1/customers/{$id}", ['email' => 'accounts@acme.test'])
            ->assertOk()
            ->assertJsonPath('data.email', 'accounts@acme.test')
            ->assertJsonPath('data.name', 'Acme LLC');
    }

    public function test_searches_and_paginates(): void
    {
        Customer::factory()->create(['name' => 'Caspian Trading']);
        Customer::factory(3)->create();

        $this->getJson('/api/v1/customers?search=Caspian')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Caspian Trading');

        $this->getJson('/api/v1/customers?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_per_page_is_capped(): void
    {
        $this->getJson('/api/v1/customers?per_page=1000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }
}
