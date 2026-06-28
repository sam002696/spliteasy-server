<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    /**
     * @throws ValidationException
     */
    public function sendPasswordResetLink(string $email): void
    {
        $userExists = User::query()
            ->where('email', $email)
            ->exists();

        if (! $userExists) {
            return;
        }

        $status = Password::sendResetLink(['email' => $email]);

        if ($status === Password::RESET_LINK_SENT) {
            return;
        }

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'email' => ['Please wait before requesting another password reset link.'],
            ]);
        }

        throw ValidationException::withMessages([
            'email' => ['Unable to send password reset link.'],
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function resetPassword(array $data): void
    {
        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => ['The password reset token is invalid or has expired.'],
        ]);
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
