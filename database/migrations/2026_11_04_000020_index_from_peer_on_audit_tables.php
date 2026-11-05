<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device page lists the sessions and file transfers a device started
 * (#149), looked up by the controlling peer's ID.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['audit_connections', 'audit_file_transfers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->index('from_peer');
            });
        }
    }

    public function down(): void
    {
        foreach (['audit_connections', 'audit_file_transfers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['from_peer']);
            });
        }
    }
};
