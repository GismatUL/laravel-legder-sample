<?php

namespace App\Services;

use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class ApiTokenService
{
    public function __construct(private readonly UserRepositoryInterface $users) {}

    /**
     * @param  list<string>|null  $abilities  null = everything the user's role allows
     *
     * @throws ValidationException
     */
    public function issue(string $email, string $password, string $deviceName, ?array $abilities): NewAccessToken
    {
        $user = $this->users->findByEmail($email);

        // Same message for unknown email and wrong password, so the endpoint can't be used to find accounts.
        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // A token never carries more than the role allows. Routes check the
        // role again on every request, so a later downgrade takes effect too.
        $allowed = $user->role->tokenAbilities();
        $abilities ??= $allowed;

        if (array_diff($abilities, $allowed) !== []) {
            throw ValidationException::withMessages(['abilities' => "Your role ({$user->role->getLabel()}) allows only: ".implode(', ', $allowed).'.']);
        }

        return $user->createToken($deviceName, $abilities, now()->addDays((int) config('ledger.api_token_days')));
    }
}
