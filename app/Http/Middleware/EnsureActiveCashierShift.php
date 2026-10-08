<?php

namespace App\Http\Middleware;

use App\Services\CashierShiftService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCashierShift
{
    public function __construct(
        private readonly CashierShiftService $cashierShiftService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->cashierShiftService->getActiveShiftForUser($user->id)) {
            $message = 'Shift kasir belum dibuka.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                ], 422);
            }

            return to_route('transactions.index')->with('error', $message);
        }

        return $next($request);
    }
}
