<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('yandex_url');
            $table->string('yandex_id')->index();
            $table->string('slug')->nullable();
            $table->string('name')->nullable();
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            // pending | parsing | ready | failed
            $table->string('parse_status')->default('pending');
            $table->unsignedTinyInteger('parse_progress')->default(0);
            $table->string('parse_error')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'yandex_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
