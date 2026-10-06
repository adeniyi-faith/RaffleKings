<?php

namespace App\Http\Requests;

use App\Support\MoneyRules;
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
            // Where to come back to after paying (item 46: the checkout the
            // customer was topping up for). Only a path on this site: it
            // must start with one "/" and hold no backslash or spaces, so it
            // can never send anyone to another website.
            // Made once per tap by the app and reused on every retry of that tap.
            'idempotency_key' => ['sometimes', ...MoneyRules::idempotencyKey()],
            'return_to' => ['nullable', 'string', 'max:500', 'regex:#^/(?![/\\\\])[^\\\\\s]*$#'],
        ];
    }
}
