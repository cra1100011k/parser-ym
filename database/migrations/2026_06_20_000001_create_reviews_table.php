<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('author_name')->nullable();
            $table->longText('text')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'external_id']);
            $table->index('reviewed_at');
            $table->index('rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
