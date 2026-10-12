<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\BusinessMembershipResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BusinessContextController extends Controller
{
    public function __construct(private readonly BusinessMembershipResolver $resolver) {}

    public function switch(Request $request): RedirectResponse
    {
        // Phase 8 UI (business switcher): only meaningful while tenancy is
        // enabled. While MULTI_BUSINESS_ENABLED=false the route is inert so it
        // can never affect a legacy/production install.
        abort_if(! config('multi_business.enabled'), 404, 'Multi-business is not enabled.');

        $businessId = $request->validate(['business_id' => ['required', 'integer']])['business_id'];
        $user = $request->user();

        $business = $user->isSuperAdmin()
            ? Business::query()
                ->whereKey((int) $businessId)
                ->where('status', Business::STATUS_ACTIVE)
                ->first()
            : $this->resolver->activeMembershipFor($user, (int) $businessId)?->business;

        abort_unless($business, 404);

        $request->session()->put('active_business_id', $business->id);

        return back();
    }
}
