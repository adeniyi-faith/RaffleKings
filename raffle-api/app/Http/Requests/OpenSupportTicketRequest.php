<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenSupportTicketRequest extends FormRequest
{
    public const MAX_SCREENSHOTS = 3;

    /** Pictures only (no SVG, which can carry scripts), up to 5 MB each. */
    public const SCREENSHOT_RULES = ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

    public function authorize(): bool
    {
        return true; // route-level auth:wordpress middleware already requires a logged-in user
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
            'screenshots' => ['nullable', 'array', 'max:'.self::MAX_SCREENSHOTS],
            'screenshots.*' => self::SCREENSHOT_RULES,
        ];
    }
}
