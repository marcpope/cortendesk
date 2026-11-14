<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #167: the client keeps a note on every address book entry and syncs it
 * like the alias. There was no column, so the note was gone on the next pull.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_book_entries', function (Blueprint $table) {
            $table->text('note')->nullable()->after('alias');
        });
    }

    public function down(): void
    {
        Schema::table('address_book_entries', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
