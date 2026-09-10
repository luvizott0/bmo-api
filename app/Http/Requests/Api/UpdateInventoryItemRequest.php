<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryItemRequest extends FormRequest
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
            'stock_category_id' => ['nullable', 'integer'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'min_quantity' => ['nullable', 'numeric', 'min:0'],
            'last_price' => ['nullable', 'numeric', 'min:0'],
            'average_price' => ['nullable', 'numeric', 'min:0'],
            'expiration_date' => ['nullable', 'date'],
            'duration_days' => ['nullable', 'integer', 'min:1'],
            'last_purchased_at' => ['nullable', 'date'],
            'is_regular_expense' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
