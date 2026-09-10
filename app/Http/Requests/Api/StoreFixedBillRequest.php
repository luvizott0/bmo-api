<?php

namespace App\Http\Requests\Api;

use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFixedBillRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'color_hex' => ['nullable', 'string', 'max:20'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'estimated_amount' => ['required', 'numeric', 'min:0.01'],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'preferred_bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'is_reminder_active' => ['nullable', 'boolean'],
            'reminder_days_before' => ['nullable', 'integer', 'between:0,30'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
