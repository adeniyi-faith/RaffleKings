<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRestrictionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level 'admin' middleware already requires an administrator
    }

    public function rules(): array
    {
        return [
            'is_banned' => ['required', 'boolean'],
            'ban_withdraw' => ['required', 'boolean'],
            'ban_transfer' => ['required', 'boolean'],
            'ban_expiry' => ['nullable', 'date'],
        ];
    }
}
