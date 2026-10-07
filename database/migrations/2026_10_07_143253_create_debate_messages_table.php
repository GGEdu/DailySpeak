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
        Schema::create('debate_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debate_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant']);
            $table->text('transcript');
            $table->string('audio_path')->nullable();
            // Messages are immutable, so there is no updated_at column.
            $table->timestamp('created_at')->useCurrent();

            $table->index('debate_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debate_messages');
    }
};
