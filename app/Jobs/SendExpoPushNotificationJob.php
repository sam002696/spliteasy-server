<?php

namespace App\Jobs;

use App\Services\Notification\ExpoPushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendExpoPushNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly int $recipientUserId,
        private readonly array $notification
    ) {}

    public function handle(ExpoPushNotificationService $expoPushNotificationService): void
    {
        $expoPushNotificationService->sendToUser($this->recipientUserId, $this->notification);
    }
}
