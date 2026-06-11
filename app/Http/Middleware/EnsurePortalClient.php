<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal session guard (P5). Requires a matched client in the session; resolves
 * the Client and stashes it on the request so handlers don't re-query. No RBAC.
 */
class EnsurePortalClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get('portal_client_id');
        $client = $id ? Client::find($id) : null;

        if ($client === null) {
            return redirect()->route('portal.login');
        }

        $request->attributes->set('portal_client', $client);

        return $next($request);
    }
}
