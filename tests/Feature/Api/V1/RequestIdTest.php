<?php

namespace Tests\Feature\Api\V1;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

class RequestIdTest extends ApiTestCase
{
    public function test_every_response_gets_a_request_id_even_an_error(): void
    {
        $id = $this->getJson('/api/v1/invoices')
            ->assertUnauthorized()
            ->headers->get(AssignRequestId::HEADER);

        $this->assertTrue(Str::isUuid((string) $id));
    }

    public function test_a_valid_incoming_id_is_kept_and_added_to_the_log_context(): void
    {
        $this->actingAsApiUser();

        $this->withHeader(AssignRequestId::HEADER, 'gateway-7f3a9c21')
            ->getJson('/api/v1/accounts')
            ->assertOk()
            ->assertHeader(AssignRequestId::HEADER, 'gateway-7f3a9c21');

        $this->assertSame('gateway-7f3a9c21', Context::get('request_id'));
    }

    public function test_an_invalid_incoming_id_is_replaced(): void
    {
        $id = $this->withHeader(AssignRequestId::HEADER, "bad id\nwith newline")
            ->getJson('/api/v1/accounts')
            ->headers->get(AssignRequestId::HEADER);

        $this->assertTrue(Str::isUuid((string) $id));
    }
}
