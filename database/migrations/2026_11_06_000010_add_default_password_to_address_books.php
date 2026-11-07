<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #162: a shared book's password for every entry that has none of its own,
 * encrypted like address_book_entries.password_enc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_books', function (Blueprint $table) {
            $table->text('default_password_enc')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('address_books', function (Blueprint $table) {
            $table->dropColumn('default_password_enc');
        });
    }
};
