<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webclient_os_logins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('peer_id');
            $table->text('password');
            $table->timestamps();
            $table->unique(['user_id', 'peer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webclient_os_logins');
    }
};
