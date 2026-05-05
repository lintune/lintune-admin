<?php

namespace App\Http\Controllers\Super;

use App\Services\KumaService;
use Illuminate\Support\Facades\Cache;

class StatusController extends Controller
{
    // Returns all monitor statuses including admin-only ones (AIO).
    public function index()
    {
        $statuses = Cache::remember('kuma.status.full', 30, function () {
            return (new KumaService())->getStatus();
        });

        return response()->json($statuses);
    }
}
