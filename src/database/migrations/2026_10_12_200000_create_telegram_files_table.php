<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('telegram_files', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->unsignedBigInteger('strategy_id')->comment('策略 id');
            $table->string('path', 500)->comment('文件路径（lsky 侧）');
            $table->string('file_id', 255)->comment('Telegram file_id');
            $table->unsignedBigInteger('message_id')->comment('频道消息 id（删除用）');
            $table->unsignedBigInteger('size')->default(0)->comment('字节数');
            $table->string('mime_type', 128)->default('')->comment('MIME 类型');
            $table->timestamps();

            $table->unique(['strategy_id', 'path']);
            $table->foreign('strategy_id')->references('id')->on('strategies')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('telegram_files');
    }
};
