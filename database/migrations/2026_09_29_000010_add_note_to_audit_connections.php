<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * End-of-session notes (issue #79).
 *
 * The controlling client asks GET /api/audit/conn/active for the session's
 * guid, then sends the note with PUT /api/audit {guid, note}. guid is set when
 * the row is created (AuditConnection::booted); rows from before this
 * migration get one lazily the first time a client asks for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_connections', function (Blueprint $table) {
            $table->uuid('guid')->nullable()->unique()->after('uuid');
            $table->text('note')->nullable()->after('guid');
            $table->foreignId('note_user_id')->nullable()->after('note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('noted_at')->nullable()->after('note_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('note_user_id');
            $table->dropUnique(['guid']);
            $table->dropColumn(['guid', 'note', 'noted_at']);
        });
    }
};
