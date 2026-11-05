<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Share links: a secret link that opens the web client for one device, for
 * someone without an account. The person at the device accepts or refuses
 * each viewer in RustDesk's own accept window.
 *
 * Only a sha256 of the link token is stored, and of each viewer's session key.
 *
 * Every NOT NULL timestamp has ->useCurrent(): without an explicit default,
 * MariaDB gives the first one ON UPDATE CURRENT_TIMESTAMP (so any save would
 * move a link's expiry) and the others an invalid zero default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('token_hash', 64)->unique();
            $table->unsignedSmallInteger('max_viewers')->default(1);
            $table->timestamp('expires_at')->useCurrent()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_share_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_share_id')->constrained()->cascadeOnDelete();
            $table->string('key_hash', 64)->unique();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->index(['device_share_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_share_sessions');
        Schema::dropIfExists('device_shares');
    }
};
