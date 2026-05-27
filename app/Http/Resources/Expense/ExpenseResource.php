<?php

namespace App\Http\Resources\Expense;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currentUserSplit = $this->relationLoaded('splits')
            ? $this->splits->firstWhere('user_id', $request->user()?->id)
            : null;

        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'description' => $this->description,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'expense_date' => $this->expense_date,
            'split_method' => $this->split_method->value,
            'status' => $this->status->value,
            'paid_by' => [
                'id' => $this->paidBy->id,
                'name' => $this->paidBy->name,
                'email' => $this->paidBy->email,
            ],
            'created_by' => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
                'email' => $this->createdBy->email,
            ],
            'your_share' => $currentUserSplit ? $currentUserSplit->amount : null,
            'splits' => ExpenseSplitResource::collection($this->whenLoaded('splits')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
