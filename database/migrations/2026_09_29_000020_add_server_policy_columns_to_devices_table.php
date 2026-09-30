<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Off = incoming only: the ID server refuses sessions this device
            // starts (issue #82). Every existing device keeps starting them.
            $table->boolean('can_initiate')->default(true)->after('status');
            // LAN address the ID server saw during a connection (issue #76).
            // Clients do not report it themselves.
            $table->string('lan_ip', 45)->nullable()->after('registered_ip');
            $table->timestamp('lan_ip_seen_at')->nullable()->after('lan_ip');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['can_initiate', 'lan_ip', 'lan_ip_seen_at']);
        });
    }
};
