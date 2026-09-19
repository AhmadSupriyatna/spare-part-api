<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Powers "user aktif sekarang" on the Dashboard. Stamps last_active_at on
 * every authenticated request, but throttled to once a minute per user —
 * a raw DB::table() update (not $user->update()) so it doesn't load the
 * full model, fire model events, or touch updated_at, and stays cheap
 * enough to run on every request rather than needing a queue.
 */
class TrackUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (! $user->last_active_at || $user->last_active_at->lt(now()->subMinute()))) {
            DB::table('users')->where('id', $user->id)->update(['last_active_at' => now()]);
        }

        return $next($request);
    }
}
