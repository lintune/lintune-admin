<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('domain_realm_map', function (Blueprint $table) {
            $table->unsignedInteger('max_users')->nullable()->after('nextcloud_enabled');
            $table->unsignedInteger('max_mailbox_users')->nullable()->after('max_users');
            $table->unsignedInteger('max_nextcloud_users')->nullable()->after('max_mailbox_users');
        });
    }

    public function down(): void
    {
        Schema::table('domain_realm_map', function (Blueprint $table) {
            $table->dropColumn(['max_users', 'max_mailbox_users', 'max_nextcloud_users']);
        });
    }
};
