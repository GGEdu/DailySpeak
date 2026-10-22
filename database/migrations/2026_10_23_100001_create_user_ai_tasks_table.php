<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The provider, model and voice a user picked for each task: conversation (tutor, translations, review, word
     * analysis), writing (final report), stt (speech-to-text) and tts (text-to-speech). The provider is an id of the
     * closed list or "platform" (the platform's connection, only where the user is allowed to use it).
     */
    public function up(): void
    {
        Schema::create('user_ai_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('task', 16);
            $table->string('provider', 32);
            $table->string('model', 120)->nullable();
            $table->string('voice', 60)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'task']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_ai_tasks');
    }
};
