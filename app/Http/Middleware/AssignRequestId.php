<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id that ties its log lines together.
 *
 * A valid X-Request-Id from the caller (a gateway or the client) is kept, so
 * one id can be followed across systems; anything else is replaced. The id
 * is added to Laravel's Context, which writes it into every log record and
 * passes it on to queued jobs dispatched during the request, so a failed
 * outbox delivery can be traced back to the API call that caused it.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $id = is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
