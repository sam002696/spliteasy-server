<?php

namespace App\Http\Requests\Group;

use Illuminate\Foundation\Http\FormRequest;

class CreateGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:50'],
            'base_currency' => ['required', 'string', 'size:3'],
            'member_emails' => ['sometimes', 'array'],
            'member_emails.*' => ['email', 'distinct', 'exists:users,email'],
        ];
    }
}
