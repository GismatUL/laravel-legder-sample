<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueTokenRequest;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @tags Authentication
 */
class AuthTokenController extends Controller
{
    /**
     * Issue an API token.
     *
     * Send the token as `Authorization: Bearer <token>`. Tokens expire after
     * 30 days by default. Request `["ledger:read"]` for a read-only token.
     *
     * @unauthenticated
     */
    public function store(IssueTokenRequest $request, ApiTokenService $tokens): JsonResponse
    {
        $token = $tokens->issue(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->string('device_name')->toString(),
            $request->abilities(),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $token->accessToken->abilities,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Revoke the current token.
     */
    public function destroy(Request $request): Response
    {
        /** @var PersonalAccessToken $token */
        $token = $request->user()->currentAccessToken();
        $token->delete();

        return response()->noContent();
    }
}
