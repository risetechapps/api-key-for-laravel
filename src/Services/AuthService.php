<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Facades\Auth;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

class AuthService
{
    public static string $ENABLE = 'enabled';
    public static string $DISABLE = 'disabled';
    public static string $BLOCKED = 'blocked';

    public static string $ADMIN = 'admin';
    public static string $CLIENT = 'client';
    public static string $EMPLOYEE = 'employee';

    /**
     * Attempt to login with credentials.
     *
     * @param array $credentials
     * @return array|null Returns user data with token on success, null on failure
     */
    public function attemptLogin(array $credentials): ?array
    {
        $user = $this->findUserByEmail($credentials['email']);

        if (!$user) {
            return null;
        }

        if (!$this->validateCredentials($credentials)) {
            return null;
        }

        return $this->buildLoginResponse($user);
    }

    /**
     * Find user by email.
     */
    public function findUserByEmail(string $email): ?Authentication
    {
        return Authentication::where('email', $email)->first();
    }

    /**
     * Validate user credentials.
     */
    private function validateCredentials(array $credentials): bool
    {
        return Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password']
        ]);
    }

    /**
     * Build login response with token.
     */
    private function buildLoginResponse(Authentication $user): array
    {
        $token = $user->createToken($user->email);

        return [
            'user' => $user,
            'token' => $token->plainTextToken,
        ];
    }

    /**
     * Get all valid login statuses.
     */
    public static function statusLogin(): array
    {
        return [
            static::$ENABLE,
            static::$DISABLE,
            static::$BLOCKED,
        ];
    }

    /**
     * Get all valid profile genres.
     */
    public static function genreProfile(): array
    {
        return ["MASCULINE", "FEMALE", "OTHER"];
    }

    /**
     * Get all valid marital statuses.
     */
    public static function maritalStatusProfile(): array
    {
        return ["SINGLE", "MARRIED", "WIDOWER", "JUDICIALLY SEPARATED"];
    }

    /**
     * Get all valid permissions.
     */
    public static function permission(): array
    {
        return [];
    }

    /**
     * Get all valid roles.
     */
    public static function roles(): array
    {
        return [
            static::$EMPLOYEE,
            static::$CLIENT,
        ];
    }
}
