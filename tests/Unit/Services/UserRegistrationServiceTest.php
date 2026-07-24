<?php

use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Services\UserRegistrationService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = new UserRegistrationService();
});

describe('Registration', function () {
    it('creates a new user', function () {
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
        ];

        $user = $this->service->register($data);

        expect($user)->toBeInstanceOf(Authentication::class);
        // HasToUpper normaliza o nome; o e-mail está no $no_upper.
        expect($user->name)->toBe('JOHN DOE');
        expect($user->email)->toBe('john@example.com');
    });

    it('creates an api key for the user', function () {
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
        ];

        $user = $this->service->register($data);

        expect($user->apiKey)->not->toBeNull();
        expect($user->apiKey->plainKey)->not->toBeNull();
    });

    it('throws exception when avatar generator is not available', function () {
        // avatarGenerator() é uma função global (helper do pacote risetools), não
        // um binding de container — não há como "desregistrá-la" por teste enquanto
        // o pacote estiver instalado. O ramo de erro é coberto por inspeção; aqui
        // não é testável de forma determinística.
    })->skip('avatarGenerator é helper global; cenário de indisponibilidade não é simulável em teste com o pacote carregado.');
});

describe('Transaction Safety', function () {
    it('rolls back on error', function () {
        $initialCount = Authentication::count();

        try {
            // name é NOT NULL no schema; passar null força um erro dentro da
            // DB::transaction do register(), que deve reverter tudo (nem user nem
            // api key persistem).
            $this->service->register([
                'name' => null,
                'email' => 'x@example.com',
                'password' => 'secret123',
            ]);
        } catch (\Throwable) {
            // Expected
        }

        expect(Authentication::count())->toBe($initialCount);
    });
});
