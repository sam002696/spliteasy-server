<?php

namespace App\Http\Resources\Group;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'group' => new GroupResource($this->whenLoaded('group')),
            'invited_by' => [
                'id' => $this->invitedBy->id,
                'name' => $this->invitedBy->name,
                'email' => $this->invitedBy->email,
            ],
            'created_at' => $this->created_at,
            'responded_at' => $this->responded_at,
        ];
    }
}
