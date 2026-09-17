<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReconcileAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level 'admin' middleware already requires an administrator
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'credits' => ['required', 'array', 'min:1'],
            'credits.*.amount' => ['required', 'numeric', 'min:0.01'],
            'credits.*.date' => ['nullable', 'string'],
            'credits.*.desc' => ['nullable', 'string'],
        ];
    }
}
