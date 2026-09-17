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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'direction' => ['required', 'in:add,subtract'],
        ];
    }
}
