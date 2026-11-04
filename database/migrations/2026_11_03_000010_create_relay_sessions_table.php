<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each connection got through (issue #130).
 *
 * relay_sessions: what CortenDesk Server's relay (hbbr) reports about every
 * session it carries, keyed by its uuid. A connection whose session ran
 * through it points at that row.
 *
 * audit_connections.path: how hbbs set the connection up, echoed by RustDesk
 * 1.5+ devices in their audit (lan, punch, punch-webrtc, relay).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relay_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 64)->unique();
            $table->string('rustdesk_id', 100)->index();
            // The console's session type (AuditConnection::TYPE_LABELS), not hbbr's ConnType.
            $table->unsignedTinyInteger('conn_type')->default(0);
            $table->string('controller_ip', 45)->nullable();
            $table->string('device_ip', 45)->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamps();
        });

        Schema::table('audit_connections', function (Blueprint $table) {
            $table->string('path', 16)->nullable()->after('conn_type');
            $table->foreignId('relay_session_id')->nullable()->after('path')
                ->constrained('relay_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('relay_session_id');
            $table->dropColumn('path');
        });
        Schema::dropIfExists('relay_sessions');
    }
};
