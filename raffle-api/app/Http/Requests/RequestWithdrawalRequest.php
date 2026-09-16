<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level auth:wordpress middleware already requires a logged-in user
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'bank_account_id' => ['required', 'integer', 'min:1'],
            'authorize_verification_fee' => ['sometimes', 'boolean'],
        ];
    }
}
