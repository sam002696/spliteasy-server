<?php

namespace App\Http\Requests\Expense;

use Illuminate\Foundation\Http\FormRequest;

class CreateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'expense_date' => ['sometimes', 'date'],
            'paid_by_user_id' => ['required', 'integer', 'exists:users,id'],
            'split_method' => ['sometimes', 'string', 'in:equal,custom,percent,shares'],
            'participant_user_ids' => ['sometimes', 'array', 'min:1'],
            'participant_user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'splits' => ['required_if:split_method,custom,percent,shares', 'array', 'min:1'],
            'splits.*.user_id' => ['required_with:splits', 'integer', 'distinct', 'exists:users,id'],
            'splits.*.amount' => ['required_if:split_method,custom', 'numeric', 'min:0.01'],
            'splits.*.value' => ['required_if:split_method,percent,shares', 'numeric', 'min:0.01'],
        ];
    }
}
