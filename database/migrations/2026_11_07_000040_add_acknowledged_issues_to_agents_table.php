<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Issue key => who acknowledged it and when. See Agent::allIssues().
            $table->json('acknowledged_issues')->nullable()->after('watch_services');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('acknowledged_issues');
        });
    }
};
