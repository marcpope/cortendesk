<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom RustDesk client builds offered for download.
 *
 * One row per uploaded installer. The bytes live on the `downloads` disk (see
 * config/filesystems.php) and are always served through
 * ClientDownloadController, never as a static path — so `filename` is a
 * generated basename this table owns and `original_name` is only ever used as
 * the name suggested to the browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_downloads', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            // windows|macos|linux|android|ios|unknown — auto-detected from the
            // uploaded filename, overridable in the uploader. Indexed because
            // the public page groups by it.
            $table->string('platform', 32)->index();
            $table->string('arch', 32)->nullable();
            $table->string('version', 64)->nullable();
            $table->string('notes', 500)->nullable();
            // Basename on the downloads disk. Generated, never taken from the
            // upload; unique so a row can never be pointed at another's bytes.
            $table->string('filename')->unique();
            $table->string('original_name');
            $table->unsignedBigInteger('size')->default(0);
            // sha256 of the stored bytes, so an operator can verify a build
            // matches what rdgen produced.
            $table->string('sha256', 64)->nullable();
            // Unpublished rows stay in the console but disappear from the
            // public page — the way to stage a build, or retire one without
            // deleting the bytes.
            $table->boolean('is_published')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('download_count')->default(0);
            // Kept for the audit trail; nullable so deleting the uploader
            // never takes their uploads with them.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_downloads');
    }
};
