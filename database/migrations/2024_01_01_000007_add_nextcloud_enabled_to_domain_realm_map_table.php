<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('domain_realm_map', function (Blueprint $table) {
            $table->boolean('nextcloud_enabled')->default(false)->after('mailcow_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('domain_realm_map', function (Blueprint $table) {
            $table->dropColumn('nextcloud_enabled');
        });
    }
};
