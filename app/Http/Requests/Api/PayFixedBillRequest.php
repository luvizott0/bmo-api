<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $workspaceId = $this->workspace()?->id;

        return [
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'payment_date' => ['nullable', 'date'],
            'bank_account_id' => [
                'nullable',
                'integer',
                Rule::exists('bank_accounts', 'id')->where(function ($query) use ($workspaceId) {
                    if ($workspaceId) {
                        $query->where('workspace_id', $workspaceId);
                    }
                }),
            ],
            'credit_card_id' => [
                'nullable',
                'integer',
                Rule::exists('credit_cards', 'id')->where(function ($query) use ($workspaceId) {
                    if ($workspaceId) {
                        $query->where('workspace_id', $workspaceId);
                    }
                }),
            ],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.numeric' => 'O valor deve ser um número válido.',
            'amount.min' => 'O valor deve ser maior que zero.',
            'payment_date.date' => 'A data de pagamento informada é inválida.',
            'bank_account_id.exists' => 'A conta bancária selecionada não foi encontrada ou não pertence a este espaço.',
            'credit_card_id.exists' => 'O cartão de crédito selecionado não foi encontrado ou não pertence a este espaço.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->filled('bank_account_id') && $this->filled('credit_card_id')) {
                $v->errors()->add('bank_account_id', 'Escolha uma conta bancária ou um cartão de crédito para pagar a conta, não ambos.');
            }
        });
    }
}
