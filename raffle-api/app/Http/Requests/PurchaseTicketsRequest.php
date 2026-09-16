<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level auth:wordpress middleware already requires a logged-in user
    }

    public function rules(): array
    {
        return [
            'raffle_id' => ['required', 'integer', 'min:1'],
            'ticket_numbers' => ['required', 'array', 'min:1'],
            'ticket_numbers.*' => ['integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0.01'],
            'is_golden_box' => ['sometimes', 'boolean'],
            'submitted_amount' => ['required', 'numeric', 'min:0.01'],
            'funding_source' => ['required', 'string', 'in:wallet,earnings'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:191'],
        ];
    }
}
