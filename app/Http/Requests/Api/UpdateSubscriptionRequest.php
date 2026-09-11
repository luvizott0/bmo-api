<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionRequest extends FormRequest
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
            'service_name' => ['sometimes', 'required', 'string', 'max:150'],
            'color_hex' => ['nullable', 'string', 'max:20'],
            'total_amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'billing_day' => ['sometimes', 'required', 'integer', 'between:1,31'],
            'credit_card_id' => ['nullable', 'integer', 'exists:credit_cards,id'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'members' => ['nullable', 'array'],
            'members.*.id' => ['nullable', 'integer'],
            'members.*.name' => ['required_with:members', 'string', 'max:150'],
            'members.*.installment_amount' => ['required_with:members', 'numeric', 'min:0.01'],
            'members.*.contact' => ['nullable', 'string', 'max:150'],
            'members.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
