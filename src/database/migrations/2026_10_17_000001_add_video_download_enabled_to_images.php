<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
// Historical compatibility: retired share/download UI no longer reads this column.
// Keep this migration so existing installations and migration history remain compatible.
return new class extends Migration {
    public function up(): void {
        Schema::table('images', function(Blueprint $table) {
            $table->boolean('video_download_enabled')->default(false);
        });
    }
    public function down(): void {
        Schema::table('images', fn(Blueprint $table) => $table->dropColumn('video_download_enabled'));
    }
};
