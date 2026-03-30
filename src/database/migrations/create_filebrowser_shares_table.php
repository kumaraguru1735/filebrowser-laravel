<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filebrowser_shares', function (Blueprint $table) {
            $table->id();
            $table->string('hash', 16)->unique();
            $table->string('path');
            $table->unsignedBigInteger('user_id');
            $table->string('password_hash')->nullable();
            $table->string('token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filebrowser_shares');
    }
};
