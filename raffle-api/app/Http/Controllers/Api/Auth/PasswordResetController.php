<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $resets) {}

    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        // Deliberately doesn't reveal whether the email has an account —
        // see PasswordResetService's docblock (a fix over the legacy
        // rk_handle_forgot_password(), which returns a 404 for an
        // unknown email).
        $this->resets->requestCode($data['email']);

        return response()->json(['message' => 'If that email has an account, a reset code has been sent.']);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
        ]);

        $this->resets->verifyCode($data['email'], $data['otp']);

        return response()->json(['message' => 'Code verified.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'min:6', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'],
        ], [
            'password.regex' => 'Password must contain at least one letter and one number.',
        ]);

        $this->resets->resetPassword($data['email'], $data['otp'], $data['password']);

        return response()->json(['message' => 'Password updated. You can now log in.']);
    }
}
