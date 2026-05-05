<?php

namespace App\Http\Controllers\Super;

use App\Services\KumaService;
use Illuminate\Support\Facades\Cache;

class StatusController extends Controller
{
    // JSON endpoint — used by the navbar dot indicators.
    public function index()
    {
        $statuses = Cache::remember('kuma.status.full', 30, function () {
            return (new KumaService())->getStatus();
        });

        return response()->json($statuses);
    }

    // Full status page.
    public function show()
    {
        $statuses = Cache::remember('kuma.status.full', 30, function () {
            return (new KumaService())->getStatus();
        });

        return view('super.status', ['monitors' => $statuses]);
    }
}
