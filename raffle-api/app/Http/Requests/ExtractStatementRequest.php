<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtractStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level 'admin' middleware already requires an administrator
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ];
    }
}
