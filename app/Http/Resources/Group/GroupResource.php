<?php

namespace App\Http\Resources\Group;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'base_currency' => $this->base_currency,
            'owner_id' => $this->owner_id,
            'members_count' => $this->whenCounted('members'),
            'expenses_count' => $this->whenCounted('expenses'),
            'latest_expense' => null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
