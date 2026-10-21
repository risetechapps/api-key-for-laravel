<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
     * @return array|null Returns user data with token on success, null on failure
     */
    public function attemptLogin(array $credentials): ?array
    {
        $user = $this->findUserByEmail($credentials['email']);

        if (! $user) {
            return null;
        }

        if (! $this->validateCredentials($credentials)) {
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
            'password' => $credentials['password'],
        ]);
    }

    /**
     * Se a senha confere com a do usuário.
     *
     * Sem usuário, confere contra um hash descartável e devolve false: o bcrypt
     * roda nos dois casos, e o tempo de resposta não diz se o e-mail existe.
     */
    public function passwordMatches(?Authentication $user, string $password): bool
    {
        if (! $user) {
            Hash::check($password, self::dummyHash());

            return false;
        }

        return Hash::check($password, (string) $user->password);
    }

    /**
     * Emite um token Sanctum para um usuário já autenticado.
     *
     * @return array{user: Authentication, token: string}
     */
    public function issueToken(Authentication $user, ?Request $request = null): array
    {
        $token = $user->createToken($user->email);

        // A tela de sessões mostra de onde cada login veio. A coluna `device`
        // existia desde a criação do pacote e nunca era preenchida.
        if ($request) {
            $token->accessToken->forceFill([
                'device' => json_encode([
                    'ip' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                ]),
            ])->save();
        }

        return [
            'user' => $user,
            'token' => $token->plainTextToken,
        ];
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
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
        return ['MASCULINE', 'FEMALE', 'OTHER'];
    }

    /**
     * Get all valid marital statuses.
     */
    public static function maritalStatusProfile(): array
    {
        return ['SINGLE', 'MARRIED', 'WIDOWER', 'JUDICIALLY SEPARATED'];
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
