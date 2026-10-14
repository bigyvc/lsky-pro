<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('r2_archive_policies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('source_strategy_id')->unique();
            $t->unsignedBigInteger('target_strategy_id');
            $t->string('source_identity', 64);
            $t->string('target_identity', 64);
            $t->unsignedBigInteger('trigger_bytes')->default(9500000000);
            $t->unsignedBigInteger('target_bytes')->default(8500000000);
            $t->boolean('enabled')->default(false);
            $t->boolean('links_ready')->default(false);
            $t->boolean('requested')->default(false);
            $t->timestamps();
        });
        Schema::create('r2_archive_jobs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('policy_id');
            $t->unsignedBigInteger('image_id');
            $t->string('source_identity', 64);
            $t->string('source_path_hash', 64);
            $t->text('source_path');
            $t->unsignedBigInteger('source_strategy_id');
            $t->unsignedBigInteger('target_strategy_id');
            $t->string('target_identity', 64);
            $t->text('target_path');
            $t->string('etag')->nullable();
            $t->unsignedBigInteger('bytes');
            $t->string('md5', 32);
            $t->string('sha1', 40);
            $t->string('phase', 20)->default('pending');
            $t->string('error', 200)->nullable();
            $t->timestamps();
            $t->unique(['source_identity', 'source_path_hash'], 'r2_archive_object_unique');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('r2_archive_jobs');
        Schema::dropIfExists('r2_archive_policies');
    }
};
