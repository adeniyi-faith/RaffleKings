<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level auth:wordpress middleware already requires a logged-in user
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }
}
