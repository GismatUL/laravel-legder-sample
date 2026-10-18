<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Support\TokenAbility;

class AuthTest extends ApiTestCase
{
    public function test_issues_a_token_that_authenticates_requests(): void
    {
        User::factory()->create(['email' => 'api@example.com']);

        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'api@example.com',
            'password' => 'password',
            'device_name' => 'integration-test',
        ])->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('abilities', TokenAbility::all());

        $this->assertNotNull($response->json('expires_at'));

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/accounts')
            ->assertOk();
    }

    public function test_wrong_credentials_get_the_same_error_as_unknown_email(): void
    {
        User::factory()->create(['email' => 'api@example.com']);

        $wrongPassword = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'api@example.com', 'password' => 'nope', 'device_name' => 'x',
        ])->assertUnprocessable()->json('errors.email');

        $unknownEmail = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'nobody@example.com', 'password' => 'nope', 'device_name' => 'x',
        ])->assertUnprocessable()->json('errors.email');

        $this->assertSame($wrongPassword, $unknownEmail);
    }

    public function test_token_endpoint_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/tokens', ['email' => 'api@example.com', 'password' => 'x', 'device_name' => 'x'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/tokens', ['email' => 'api@example.com', 'password' => 'x', 'device_name' => 'x'])
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too Many Attempts.');
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->getJson('/api/v1/invoices')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_read_only_token_cannot_write(): void
    {
        $this->actingAsApiUser([TokenAbility::READ]);

        $this->getJson('/api/v1/customers')->assertOk();
        $this->postJson('/api/v1/customers', ['name' => 'Acme'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Invalid ability provided.');
    }

    public function test_api_docs_are_not_public_outside_local(): void
    {
        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_revoking_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', TokenAbility::all())->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
