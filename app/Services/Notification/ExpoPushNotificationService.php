<?php

namespace App\Services\Notification;

use App\Models\UserPushToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushNotificationService
{
    public function sendToUser(int $userId, array $notification): void
    {
        $tokens = UserPushToken::query()
            ->where('user_id', $userId)
            ->where('provider', 'expo')
            ->whereNull('revoked_at')
            ->pluck('token')
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $messages = $tokens
            ->map(fn (string $token): array => $this->buildMessage($token, $notification))
            ->all();

        $response = Http::timeout(10)
            ->acceptJson()
            ->asJson()
            ->post(config('services.expo.push_url'), $messages);

        Log::info('Expo push notification response', [
            'user_id' => $userId,
            'status' => $response->status(),
            'response' => $response->json(),
        ]);

        if (! $response->successful()) {
            Log::warning('Expo push notification request failed', [
                'user_id' => $userId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return;
        }

        $this->revokeInvalidTokens($tokens->all(), $response->json('data', []));
    }

    private function buildMessage(string $token, array $notification): array
    {
        return [
            'to' => $token,
            'title' => config('services.expo.default_title', 'SplitEasy'),
            'body' => $notification['title'] ?? 'You have a new notification.',
            'sound' => 'default',
            'channelId' => 'default',
            'data' => [
                'notification' => [
                    'id' => $notification['id'] ?? null,
                    'activity_id' => $notification['activity_id'] ?? null,
                    'type' => $notification['type'] ?? null,
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<int, array<string, mixed>>  $tickets
     */
    private function revokeInvalidTokens(array $tokens, array $tickets): void
    {
        foreach ($tickets as $index => $ticket) {
            if (($ticket['status'] ?? null) !== 'error') {
                continue;
            }

            $error = $ticket['details']['error'] ?? null;
            $token = $tokens[$index] ?? null;

            Log::warning('Expo push notification ticket failed', [
                'token' => $token,
                'error' => $error,
                'message' => $ticket['message'] ?? null,
            ]);

            if ($token && $error === 'DeviceNotRegistered') {
                UserPushToken::query()
                    ->where('token', $token)
                    ->whereNull('revoked_at')
                    ->update([
                        'revoked_at' => now(),
                    ]);
            }
        }
    }
}
