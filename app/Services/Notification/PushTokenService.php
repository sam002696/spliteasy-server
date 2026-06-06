<?php

namespace App\Services\Notification;

use App\Models\User;
use App\Models\UserPushToken;

class PushTokenService
{
    public function store(User $user, array $data): UserPushToken
    {
        return UserPushToken::query()->updateOrCreate([
            'token' => $data['token'],
        ], [
            'user_id' => $user->id,
            'provider' => 'expo',
            'platform' => $data['platform'] ?? null,
            'device_id' => $data['device_id'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'last_used_at' => now(),
            'revoked_at' => null,
        ]);
    }

    public function revoke(User $user, string $token): int
    {
        return UserPushToken::query()
            ->where('user_id', $user->id)
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
            ]);
    }
}
