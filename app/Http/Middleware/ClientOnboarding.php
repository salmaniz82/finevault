<?php

namespace App\Http\Middleware;

use App\Services\Workflow;
use Closure;
use Illuminate\Http\Request;

class ClientOnboarding
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user?->role === 'client' && $request->is('signing-demo', 'signing-demo/*', 'direct-signing-demo', 'direct-signing-demo/*', 'activity', 'approvals', 'managers')) {
            abort(403);
        }
        if ($user?->role === 'client' && ! Workflow::approved($user)
            && ! $request->is('onboarding', 'onboarding/*', 'email/*', 'account', 'logout', 'notifications', 'notifications/*')) {
            return $request->expectsJson() ? response()->json(['message' => 'Complete onboarding first.'], 403) : redirect('/onboarding');
        }

        if ($user?->role === 'client' && Workflow::approved($user) && ! Workflow::fullAccess($user)
            && $request->isMethod('get') && $request->is('dashboard', 'holdings', 'requests')) {
            return redirect('/deals');
        }

        return $next($request);
    }
}
