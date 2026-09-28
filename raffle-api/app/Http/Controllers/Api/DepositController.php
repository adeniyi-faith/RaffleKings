<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InitializeDepositRequest;
use App\Models\Deposit;
use App\Models\Legacy\WpUser;
use App\Services\DepositService;
use App\Services\Monitoring\ErrorAlerter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DepositController extends Controller
{
    public function __construct(private readonly DepositService $deposits) {}

    public function store(InitializeDepositRequest $request, ErrorAlerter $alerts): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        try {
            $deposit = $this->deposits->initialize(
                $user,
                (float) $request->float('amount'),
                url('/api/deposits/callback'),
            );
        } catch (PaymentGatewayException $e) {
            // The gateway's own error text is technical (it can name
            // missing settings, e.g. "missing PAYSTACK_SECRET_KEY") — it
            // goes to the log and the admins, never to the customer.
            Log::error('Deposit could not be started.', ['user_id' => $user->ID, 'error' => $e->getMessage()]);
            $alerts->problem('Top-up could not start', [Str::limit($e->getMessage(), 400)]);

            return response()->json([
                'message' => "We couldn't start your payment right now. Please try again in a few minutes. If it keeps happening, contact support — no money has been taken.",
            ], 503);
        }

        return response()->json($deposit, 201);
    }

    /** Lets the frontend poll a deposit's status after the user returns from the gateway's checkout page. */
    public function show(Request $request, Deposit $deposit): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        if ($deposit->user_id !== $user->ID) {
            return response()->json(['message' => 'Deposit not found.'], 404);
        }

        return response()->json($deposit);
    }

    /**
     * Where `DepositController::store()`'s `callback_url` sends the
     * user's browser back after Paystack/Flutterwave's hosted checkout —
     * a real gap discovered while building item 26's wallet top-up page:
     * `initialize()` was already passing a `callback_url` of
     * `/api/deposits/callback` to the gateway, but no route ever existed
     * to receive that redirect, so a user completing a real payment
     * would have landed on a 404 instead of back in the app. This
     * re-verifies the deposit the same way the webhook does (never
     * trusting the redirect's own query params for settlement, only for
     * which reference to look up) — safe to call even if the webhook
     * already settled it first, since `DepositService::confirm()` is
     * idempotent — and then sends the browser to the wallet page to show
     * the result.
     */
    public function callback(Request $request): RedirectResponse
    {
        $reference = (string) $request->query('reference', $request->query('trxref', ''));

        $deposit = Deposit::query()->where('reference', $reference)->first();

        if (! $deposit) {
            return redirect('/account/wallet');
        }

        if ($deposit->gateway && ! in_array($deposit->status, ['successful', 'amount_mismatch'], true)) {
            try {
                $this->deposits->confirm($deposit->gateway, $reference);
            } catch (PaymentGatewayException) {
                // Fall through — the webhook (or a manual retry) may still
                // settle this; the wallet page's status poll will reflect
                // whatever the deposit's current status actually is.
            }
        }

        return redirect('/account/wallet?deposit='.$deposit->id);
    }
}
