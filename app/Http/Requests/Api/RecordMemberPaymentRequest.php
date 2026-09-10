<?php

namespace App\Http\Requests\Api;

use App\Enums\SubscriptionPaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordMemberPaymentRequest extends FormRequest
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
            'reference_month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'status' => ['required', Rule::enum(SubscriptionPaymentStatus::class)],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'payment_date' => ['nullable', 'date'],
        ];
    }
}
