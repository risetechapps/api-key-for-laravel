<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RuntimeException;

class UserRegistrationService
{
    /**
     * Register a new user with avatar and API key.
     *
     * @param array $data Validated registration data
     * @return Authentication
     * @throws RuntimeException
     */
    public function register(array $data): Authentication
    {
        return DB::transaction(function () use ($data) {
            $user = $this->createUser($data);
            $this->createApiKey($user);
            $this->generateAndStoreAvatar($user, $data['name']);

            return $user;
        });
    }

    /**
     * Create the user record.
     */
    private function createUser(array $data): Authentication
    {
        return Authentication::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
    }

    /**
     * Create API key for the user.
     */
    private function createApiKey(Authentication $user): void
    {
        $plainApiKey = bin2hex(random_bytes(64));

        $user->apiKey()->create([
            'key' => $plainApiKey,
            'active' => false,
        ]);

        // Store temporarily for return
        $user->apiKey->plainKey = $plainApiKey;
    }

    /**
     * Generate and store avatar for the user.
     *
     * @throws RuntimeException
     */
    private function generateAndStoreAvatar(Authentication $user, string $name): void
    {
        $this->validateAvatarGenerator();

        $avatarPath = $user->getKey() . '.png';
        $profileImage = avatarGenerator()->generateBase64($name);

        if (!Storage::put($avatarPath, $profileImage)) {
            throw new RuntimeException('Unable to persist generated avatar.');
        }

        $user->addMediaFromDisk($avatarPath)->toMediaCollection('profile');
    }

    /**
     * Validate that avatar generator helper exists.
     *
     * @throws RuntimeException
     */
    private function validateAvatarGenerator(): void
    {
        if (!function_exists('avatarGenerator')) {
            throw new RuntimeException(
                'avatarGenerator helper is not available. Please install risetechapps/risetools package.'
            );
        }
    }
}
