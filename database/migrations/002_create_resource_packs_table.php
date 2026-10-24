<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_packs', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();

            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            $table->string('name');
            $table->string('original_name');
            $table->unsignedBigInteger('size');
            $table->char('sha1', 40);
            $table->string('token', 64)->unique();
            $table->unsignedInteger('pack_format')->nullable();
            $table->string('description', 512)->nullable();
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_packs');
    }
};
