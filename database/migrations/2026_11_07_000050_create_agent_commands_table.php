<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Command types the agent build runs, from its last report.
            $table->json('capabilities')->nullable()->after('acknowledged_issues');
        });

        Schema::create('agent_commands', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('type', 32);
            $table->json('params')->nullable();
            $table->string('status', 16)->default('queued');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Kept after the user is deleted; "system" for automatic updates.
            $table->string('created_by_name', 191)->nullable();
            // Shared by the commands one bulk action queued.
            $table->string('batch', 26)->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('output')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('capabilities');
        });
    }
};
