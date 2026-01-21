<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsStaff
{
    
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && in_array($request->user()->role, ['admin', 'purchaser', 'cashier'])) {
            return $next($request);
        }

        return response()->json(['message' => 'Forbidden. Staff access required.'], 403);
    }
}
