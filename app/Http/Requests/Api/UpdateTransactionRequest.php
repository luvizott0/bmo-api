<?php

namespace App\Http\Requests\Api;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTransactionRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', Rule::enum(TransactionType::class)],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'occurred_at' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', Rule::enum(TransactionStatus::class)],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'credit_card_id' => ['nullable', 'integer', 'exists:credit_cards,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'fixed_bill_id' => ['nullable', 'integer', 'exists:fixed_bills,id'],
            'subscription_id' => ['nullable', 'integer', 'exists:subscriptions,id'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Additional validation rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->filled('bank_account_id') && $this->filled('credit_card_id')) {
                $v->errors()->add('bank_account_id', 'A transaction cannot be linked to both a bank account and a credit card.');
            }
        });
    }
}
