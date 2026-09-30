<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HoldNumbersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guests hold numbers too; NumberHoldController works out who is asking
    }

    public function rules(): array
    {
        return [
            'numbers' => ['required', 'array', 'min:1', 'max:100'],
            'numbers.*' => ['integer', 'min:1'],
            'guest_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
