<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('realm_config', function (Blueprint $table) {
            $table->id();
            $table->string('realm');
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();

            $table->unique(['realm', 'key']);
            $table->index('realm');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realm_config');
    }
};
