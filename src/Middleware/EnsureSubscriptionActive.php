<?php

namespace Kicol\FullFlow\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        $subscription = method_exists($user, 'fullflowSubscription')
            ? $user->fullflowSubscription
            : null;

        if (!$subscription) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'no_subscription'], 402);
            }
            return redirect()->route('subscribe');
        }

        $allowed = config('fullflow.access_allowed_statuses', ['trial', 'ativa', 'past_due', 'cancelamento_agendado']);

        if (!in_array($subscription->status, $allowed, true)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'subscription_blocked', 'status' => $subscription->status], 402);
            }
            return redirect()->route('subscription.blocked');
        }

        return $next($request);
    }
}
