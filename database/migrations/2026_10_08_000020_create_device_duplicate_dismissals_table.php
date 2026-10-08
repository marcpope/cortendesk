<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pairs an operator marked as two real machines rather than one machine
     * under two RustDesk IDs (issue #91). device_id is always the lower id of
     * the pair, so each pair has exactly one row.
     */
    public function up(): void
    {
        Schema::create('device_duplicate_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('other_device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(); // nullable: no MariaDB implicit default

            $table->unique(['device_id', 'other_device_id']);
            $table->index('other_device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_duplicate_dismissals');
    }
};
