<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gallery_site_settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->text('payload');
        });
        Schema::create('gallery_posts', function (Blueprint $t) {
            $t->id();
            $t->string('public_key', 32)->unique();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('album_id');
            $t->unsignedBigInteger('strategy_id');
            $t->string('source_group', 100);
            $t->unsignedBigInteger('source_order')->default(0);
            $t->timestamps();
            $t->unique(['user_id','album_id','strategy_id','source_group'], 'gallery_source_unique');
            $t->index(['user_id','album_id','source_order','id'], 'gallery_album_order');
        });
        Schema::create('gallery_post_images', function (Blueprint $t) {
            $t->foreignId('post_id')->constrained('gallery_posts')->onDelete('cascade');
            $t->foreignId('image_id')->constrained('images')->onDelete('cascade');
            $t->unsignedTinyInteger('position');
            $t->primary(['post_id','image_id']);
            $t->index('image_id');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('gallery_post_images');
        Schema::dropIfExists('gallery_posts');
        Schema::dropIfExists('gallery_site_settings');
    }
};
