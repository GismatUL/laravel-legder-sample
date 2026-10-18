<?php

namespace Tests\Feature\Api\V1;

use App\Data\ManualEntryLineData;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\TokenAbility;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Role checks are independent of token abilities: every test here uses a
 * token with ledger:write, so a 403 comes from the role (the policy).
 */
class AuthorizationTest extends ApiTestCase
{
    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->role($role)->create();
        Sanctum::actingAs($user, TokenAbility::all());

        return $user;
    }

    /**
     * Every write route, with a valid payload, so a 403 can't be confused
     * with a 422.
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    private function writeRoutes(): array
    {
        $customer = Customer::factory()->create();
        $draft = $this->postJson('/api/v1/invoices', $this->invoicePayload($customer))->json('data.id');
        $issued = $this->issuedInvoice();
        $entry = $this->manualEntry();

        return [
            'create account' => ['POST', '/api/v1/accounts', ['code' => '7000', 'name' => 'Travel', 'type' => 'expense']],
            'update account' => ['PATCH', '/api/v1/accounts/'.$this->accountByCode('6000')->id, ['name' => 'Renamed']],
            'create customer' => ['POST', '/api/v1/customers', ['name' => 'Acme']],
            'update customer' => ['PATCH', "/api/v1/customers/{$customer->id}", ['name' => 'Acme 2']],
            'create invoice' => ['POST', '/api/v1/invoices', $this->invoicePayload($customer)],
            'update invoice' => ['PUT', "/api/v1/invoices/{$draft}", $this->invoicePayload($customer)],
            'delete invoice' => ['DELETE', "/api/v1/invoices/{$draft}", []],
            'issue invoice' => ['POST', "/api/v1/invoices/{$draft}/issue", []],
            'void invoice' => ['POST', "/api/v1/invoices/{$issued->id}/void", []],
            'record payment' => ['POST', "/api/v1/invoices/{$issued->id}/payments", ['amount' => '1.00', 'paid_on' => today()->toDateString(), 'method' => 'cash']],
            'post journal entry' => ['POST', '/api/v1/journal-entries', $this->entryPayload()],
            'reverse journal entry' => ['POST', "/api/v1/journal-entries/{$entry->id}/reversal", []],
            'import statement' => ['POST', '/api/v1/bank-statements', ['statement' => UploadedFile::fake()->createWithContent('s.csv', "date,amount,reference\n2026-10-01,1.00,x\n")]],
        ];
    }

    public function test_a_viewer_can_read_everything_and_change_nothing(): void
    {
        $this->actingAsRole(UserRole::Admin);
        $routes = $this->writeRoutes();

        $this->actingAsRole(UserRole::Viewer);

        foreach (['/api/v1/accounts', '/api/v1/customers', '/api/v1/invoices', '/api/v1/journal-entries', '/api/v1/reports/trial-balance', '/api/v1/bank-transactions'] as $uri) {
            $this->getJson($uri)->assertOk();
        }

        $before = [Account::count(), Customer::count(), JournalEntry::count()];

        foreach ($routes as $name => [$method, $uri, $payload]) {
            $this->json($method, $uri, $payload, ['Idempotency-Key' => "k-{$name}"])
                ->assertForbidden()
                ->assertJsonPath('code', 'forbidden');
        }

        $this->assertSame($before, [Account::count(), Customer::count(), JournalEntry::count()]);
    }

    public function test_an_accountant_does_bookkeeping_but_cannot_change_the_chart_of_accounts(): void
    {
        $this->actingAsRole(UserRole::Accountant);

        $this->postJson('/api/v1/accounts', ['code' => '7000', 'name' => 'Travel', 'type' => 'expense'])->assertForbidden();
        $this->patchJson('/api/v1/accounts/'.$this->accountByCode('6000')->id, ['name' => 'Renamed'])->assertForbidden();

        $this->postJson('/api/v1/customers', ['name' => 'Acme'])->assertCreated();
        $this->postJson('/api/v1/journal-entries', $this->entryPayload())->assertCreated();
    }

    public function test_an_admin_can_change_the_chart_of_accounts(): void
    {
        $this->actingAsRole(UserRole::Admin);

        $this->postJson('/api/v1/accounts', ['code' => '7000', 'name' => 'Travel', 'type' => 'expense'])->assertCreated();
    }

    public function test_a_viewer_gets_a_read_only_token_by_default(): void
    {
        User::factory()->role(UserRole::Viewer)->create(['email' => 'auditor@example.com']);

        $this->postJson('/api/v1/auth/tokens', ['email' => 'auditor@example.com', 'password' => 'password', 'device_name' => 'x'])
            ->assertCreated()
            ->assertJsonPath('abilities', [TokenAbility::READ]);
    }

    public function test_a_viewer_cannot_request_a_write_token(): void
    {
        User::factory()->role(UserRole::Viewer)->create(['email' => 'auditor@example.com']);

        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'auditor@example.com', 'password' => 'password', 'device_name' => 'x', 'abilities' => [TokenAbility::WRITE],
        ])->assertUnprocessable()->assertJsonValidationErrors(['abilities']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_role_downgrade_applies_to_tokens_issued_before_it(): void
    {
        $user = User::factory()->role(UserRole::Accountant)->create();
        $token = $user->createToken('before-downgrade', TokenAbility::all())->plainTextToken;

        $user->forceFill(['role' => UserRole::Viewer])->save();

        $this->withToken($token)->postJson('/api/v1/customers', ['name' => 'Acme'])->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/customers')->assertOk();
    }

    public function test_the_role_is_not_mass_assignable(): void
    {
        // Strict mode (non-production) throws on a discarded attribute; production silently drops it.
        $this->expectException(MassAssignmentException::class);

        new User(['name' => 'x', 'email' => 'x@example.com', 'password' => 'secret', 'role' => 'admin']);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(Customer $customer): array
    {
        return [
            'customer_id' => $customer->id,
            'lines' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => '10.00', 'tax_rate_bp' => 0]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryPayload(): array
    {
        return [
            'entry_date' => today()->toDateString(),
            'description' => 'Office supplies',
            'lines' => [
                ['account_id' => $this->accountByCode('6000')->id, 'debit' => '5.00'],
                ['account_id' => $this->account('bank')->id, 'credit' => '5.00'],
            ],
        ];
    }

    private function manualEntry(): JournalEntry
    {
        return app(LedgerService::class)->postManual(today(), 'Seed', array_values(array_map(
            ManualEntryLineData::fromInput(...),
            $this->entryPayload()['lines'],
        )));
    }

    /** @return array<string, array{UserRole}> */
    public static function roles(): array
    {
        return array_combine(
            array_map(fn (UserRole $role) => $role->value, UserRole::cases()),
            array_map(fn (UserRole $role) => [$role], UserRole::cases()),
        );
    }

    #[DataProvider('roles')]
    public function test_every_role_can_read_reports(UserRole $role): void
    {
        $this->actingAsRole($role);

        $this->getJson('/api/v1/reports/trial-balance')->assertOk();
    }
}
