<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function register(array $data): array
    {
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        return $this->respondWithToken($user);
    }

    /**
     * @throws AuthenticationException
     */
    public function login(array $credentials): array
    {
        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid login credentials.');
        }

        return $this->respondWithToken($user);
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function me(User $user): User
    {
        return $user;
    }

    private function respondWithToken(User $user): array
    {
        return [
            'token_type' => 'Bearer',
            'token' => $user->createToken('mobile-auth-token')->plainTextToken,
            'user' => $user,
        ];
    }
}
