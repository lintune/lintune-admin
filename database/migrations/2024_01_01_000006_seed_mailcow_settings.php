<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $url    = env('MAILCOW_URL');
        $apiKey = env('MAILCOW_API_KEY');

        if ($url) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'mailcow.url'],
                ['value' => $url, 'encrypted' => false, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        if ($apiKey) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'mailcow.api_key'],
                ['value' => encrypt($apiKey), 'encrypted' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['mailcow.url', 'mailcow.api_key'])->delete();
    }
};
