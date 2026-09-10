<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PayFixedBillRequest extends FormRequest
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
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'payment_date' => ['nullable', 'date'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'credit_card_id' => ['nullable', 'integer', 'exists:credit_cards,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->filled('bank_account_id') && $this->filled('credit_card_id')) {
                $v->errors()->add('bank_account_id', 'Choose a bank account or a credit card to pay the bill, not both.');
            }
        });
    }
}
