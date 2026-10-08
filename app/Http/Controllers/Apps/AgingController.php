<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class AgingController extends Controller
{
    public function index()
    {
        return Inertia::render('Dashboard/Aging/Index');
    }
}
