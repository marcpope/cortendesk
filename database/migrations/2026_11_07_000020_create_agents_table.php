<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring: one row per machine running the CortenDesk Agent. `latest` is
 * the last report body so the dashboard never reads agent_samples.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('secret_hash', 64);
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('device_group_id')->nullable()->constrained('device_groups')->nullOnDelete();
            $table->foreignId('enrollment_token_id')->nullable()->constrained('agent_enrollment_tokens')->nullOnDelete();
            $table->string('machine_uuid')->unique();
            $table->string('rustdesk_id')->nullable()->index();
            $table->string('hostname')->nullable();
            $table->string('os', 16)->nullable();
            $table->string('os_version')->nullable();
            $table->string('arch', 16)->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('last_ip', 45)->nullable();
            $table->json('latest')->nullable();
            $table->json('watch_services')->nullable();
            $table->json('inventory')->nullable();
            $table->string('inventory_hash', 64)->nullable();
            $table->timestamp('inventory_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
