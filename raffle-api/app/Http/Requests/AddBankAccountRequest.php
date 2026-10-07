<?php

namespace App\Http\Requests;

use App\Support\Features;
use Illuminate\Foundation\Http\FormRequest;

class AddBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level auth:wordpress middleware already requires a logged-in user
    }

    public function rules(): array
    {
        // With the bank-name check on, the customer only picks the bank and
        // types the number; the name comes from Paystack, never the form.
        if (Features::bankNameCheck()) {
            return [
                'bank_code' => ['required', 'string', 'max:20'],
                'account_number' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
            ];
        }

        return [
            'bank_name' => ['required', 'string', 'min:2', 'max:100'],
            'account_number' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
            'account_name' => ['required', 'string', 'regex:/^[A-Za-z .\'-]{3,80}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_code.required' => 'Choose your bank from the list.',
            'account_number.regex' => 'Nigerian account numbers must be exactly 10 digits.',
            'account_name.regex' => 'Enter the account name as it appears at the bank.',
        ];
    }
}
