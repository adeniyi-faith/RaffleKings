<?php

namespace App\Http\Middleware;

use App\Services\Admin\Impersonation;
use App\Services\Retention\MemberSegments;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes the days a signed-in customer opens the site, for member segments
 * ("repeat visitor", "visits but doesn't buy"). One write per customer per
 * day at most. Not for the admin, and not while staff view as a customer.
 */
class RememberVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $request->is('admin', 'admin/*', 'livewire/*', 'm/*')) {
            return $response;
        }

        if (($user = Auth::guard('wordpress')->user()) && ! Impersonation::current($request)) {
            MemberSegments::recordVisit((int) $user->getKey());
        }

        return $response;
    }
}
