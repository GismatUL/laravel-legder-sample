<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A business rule was violated. Carries a stable, machine-readable error
 * code so API clients can react to it without parsing the message.
 */
abstract class DomainRuleException extends DomainException
{
    final protected function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status);
    }
}
