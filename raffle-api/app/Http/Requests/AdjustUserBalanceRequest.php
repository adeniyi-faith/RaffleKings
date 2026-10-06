<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustUserBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level 'admin' middleware already requires an administrator
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:wallet,earnings,points'],
            'amount' => \App\Support\MoneyRules::amount(),
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'direction' => ['required', 'in:add,subtract'],
        ];
    }
}
