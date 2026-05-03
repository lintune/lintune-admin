<?php

namespace App\Http\Controllers\Super;

use App\Models\Setting;
use App\Services\KumaService;
use Illuminate\Support\Facades\Cache;

class StatusController extends Controller
{
    // Returns all monitor statuses including admin-only ones (AIO).
    public function index()
    {
        $statuses = Cache::remember('kuma.status.full', 30, function () {
            $rawKey = Setting::get('kuma.api_key');
            if (!$rawKey) {
                return [];
            }
            return (new KumaService())->getStatus(decrypt($rawKey));
        });

        return response()->json($statuses);
    }
}
