<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Until now the relay field on Settings → Server had no effect on the ID
     * server bundled in the container, and every settings save stored a copy
     * of the environment's address in it. From this release hbbs hands a
     * saved address to clients (#181), so an old copy that no longer matches
     * the environment would break relayed sessions. Clear it once; the field
     * shows the environment's address again until someone saves another.
     * The relay pool is left alone: nothing ever filled it in by itself.
     */
    public function up(): void
    {
        if (config('cortendesk.embedded_server')) {
            DB::table('settings')->where('key', 'relay_server')->delete();
        }
    }

    public function down(): void
    {
        // The cleared value cannot be brought back.
    }
};
