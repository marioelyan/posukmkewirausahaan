<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravolt\Indonesia\Models\City;
use Laravolt\Indonesia\Models\District;
use Laravolt\Indonesia\Models\Village;

class RegionController extends Controller
{
    public function regencies(Request $request)
    {
        $request->validate(['province_id' => 'required|string']);

        return City::where('province_code', $request->province_id)
            ->select('code', 'name')
            ->orderBy('name')
            ->get();
    }

    public function districts(Request $request)
    {
        $request->validate(['regency_id' => 'required|string']);

        return District::where('city_code', $request->regency_id)
            ->select('code', 'name')
            ->orderBy('name')
            ->get();
    }

    public function villages(Request $request)
    {
        $request->validate(['district_id' => 'required|string']);

        return Village::where('district_code', $request->district_id)
            ->select('code', 'name')
            ->orderBy('name')
            ->get();
    }
}
