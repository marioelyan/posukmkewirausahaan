<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TourController extends Controller
{
    public const TOURS = ['dashboard', 'pos', 'products', 'cashier_shifts', 'reports'];

    public function complete(Request $request, string $tour): JsonResponse
    {
        abort_unless(in_array($tour, self::TOURS, true), 404);

        /** @var User $user */
        $user = $request->user();

        $completed = $user->completed_tours ?? [];

        if (! in_array($tour, $completed, true)) {
            $user->update(['completed_tours' => array_values([...$completed, $tour])]);
        }

        return response()->json(['completed' => $user->completed_tours]);
    }

    public function reset(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->update(['completed_tours' => null]);

        return response()->json(['completed' => []]);
    }
}
