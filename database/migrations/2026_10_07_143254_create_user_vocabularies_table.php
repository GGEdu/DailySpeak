<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_vocabularies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('word');
            $table->unsignedTinyInteger('mastery_level')->default(1);
            $table->timestamp('next_review_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'word']);
            // Spaced repetition lookup: "words due for review for this user".
            $table->index(['user_id', 'next_review_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE user_vocabularies ADD CONSTRAINT user_vocabularies_mastery_level_check CHECK (mastery_level BETWEEN 1 AND 5)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_vocabularies');
    }
};
