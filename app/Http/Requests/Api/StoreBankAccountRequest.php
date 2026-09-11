<?php

namespace App\Http\Requests\Api;

use App\Enums\BankAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreBankAccountRequest extends FormRequest
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
            'bank_name' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['nullable', new Enum(BankAccountType::class)],
            'current_balance' => ['nullable', 'numeric'],
            'color_hex' => ['nullable', 'string', 'max:7'],
            'is_active' => ['nullable', 'boolean'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
