<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
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
            'service_name' => ['required', 'string', 'max:150'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'billing_day' => ['required', 'integer', 'between:1,31'],
            'credit_card_id' => ['nullable', 'integer', 'exists:credit_cards,id'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'notes' => ['nullable', 'string'],
            'members' => ['nullable', 'array'],
            'members.*.name' => ['required_with:members', 'string', 'max:150'],
            'members.*.installment_amount' => ['required_with:members', 'numeric', 'min:0.01'],
            'members.*.contact' => ['nullable', 'string', 'max:150'],
            'members.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
