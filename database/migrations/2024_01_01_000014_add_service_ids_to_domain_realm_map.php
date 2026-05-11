<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('domain_realm_map', function (Blueprint $table) {
            $table->foreignId('mailcow_service_id')->nullable()->after('mailcow_enabled')
                ->constrained('server_services')->nullOnDelete();
            $table->foreignId('nextcloud_service_id')->nullable()->after('nextcloud_enabled')
                ->constrained('server_services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domain_realm_map', function (Blueprint $table) {
            $table->dropForeign(['mailcow_service_id']);
            $table->dropForeign(['nextcloud_service_id']);
            $table->dropColumn(['mailcow_service_id', 'nextcloud_service_id']);
        });
    }
};
