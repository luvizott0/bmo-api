<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AddSubscriptionMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'installment_amount' => ['required', 'numeric', 'min:0.01'],
            'contact' => ['nullable', 'string', 'max:150'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
