<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(1)->after('status');
            // Set only while a retry is pending. Nullable, so MariaDB adds no
            // implicit default or on-update clause.
            $table->timestamp('next_retry_at')->nullable()->after('attempts')->index();
            // The message body, kept only until the row stops being retryable.
            $table->text('body')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex(['next_retry_at']);
            $table->dropColumn(['attempts', 'next_retry_at', 'body']);
        });
    }
};
