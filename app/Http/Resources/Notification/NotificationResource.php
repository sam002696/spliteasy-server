<?php

namespace App\Http\Resources\Notification;

use App\Services\Notification\NotificationPayloadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return app(NotificationPayloadService::class)->build($this->resource, $request->user());
    }
}
