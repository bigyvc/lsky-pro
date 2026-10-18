<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('gallery_video_metadata', function (Blueprint $table) {
            $table->foreignId('image_id')->primary()->constrained('images')->onDelete('cascade');
            $table->string('fingerprint',64);
            $table->double('duration')->nullable();
            $table->string('poster')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('gallery_video_metadata'); }
};
