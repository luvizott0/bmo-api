<?php

namespace App\Http\Requests\Api;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTransactionRequest extends FormRequest
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
            'type' => ['required', Rule::enum(TransactionType::class)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'occurred_at' => ['required', 'date'],
            'status' => ['required', Rule::enum(TransactionStatus::class)],
            'description' => ['required', 'string', 'max:255'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'credit_card_id' => ['nullable', 'integer', 'exists:credit_cards,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'fixed_bill_id' => ['nullable', 'integer', 'exists:fixed_bills,id'],
            'subscription_id' => ['nullable', 'integer', 'exists:subscriptions,id'],
            'notes' => ['nullable', 'string'],
            'is_installment' => ['nullable', 'boolean'],
            'installments_count' => ['nullable', 'integer', 'min:1', 'max:72'],
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

            if ($this->input('type') === TransactionType::Income->value && $this->filled('credit_card_id')) {
                $v->errors()->add('credit_card_id', 'Income cannot be assigned to a credit card.');
            }

            if ($this->boolean('is_installment') && ! $this->filled('credit_card_id')) {
                $v->errors()->add('credit_card_id', 'Installment transactions must be linked to a credit card.');
            }
        });
    }
}
