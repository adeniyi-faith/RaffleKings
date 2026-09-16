<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitializeDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level auth:wordpress middleware already requires a logged-in user
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:'.config('payments.minimum_deposit')],
        ];
    }
}
