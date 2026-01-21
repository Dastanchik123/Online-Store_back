<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ($request->user()->role === 'admin' || $request->user()->role === 'purchaser')) {
            return $next($request);
        }

        return response()->json(['message' => 'Forbidden. Admin or Purchaser access required.'], 403);
    }
}
