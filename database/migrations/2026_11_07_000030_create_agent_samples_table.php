<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring: one row per agent report, for the charts. Pruned nightly by
 * monitoring_retention_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->timestamp('taken_at')->useCurrent();
            $table->decimal('cpu_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('mem_used')->nullable();
            $table->unsignedBigInteger('mem_total')->nullable();
            $table->unsignedBigInteger('swap_used')->nullable();
            $table->decimal('disk_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('rx_bps')->nullable();
            $table->unsignedBigInteger('tx_bps')->nullable();
            $table->decimal('load1', 8, 2)->nullable();

            $table->index(['agent_id', 'taken_at']);
            $table->index('taken_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_samples');
    }
};
