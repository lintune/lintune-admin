<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('server_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->enum('service', ['keycloak', 'mailcow', 'nextcloud']);
            $table->string('service_url');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['server_id', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_services');
    }
};
