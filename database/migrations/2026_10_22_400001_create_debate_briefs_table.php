<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The brief of an article for one English level, written once and shared by every guided debate on that article
     * at that level. It is derived from the article, so it holds no personal data.
     */
    public function up(): void
    {
        Schema::create('debate_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_article_id')->constrained()->cascadeOnDelete();
            $table->string('level', 2);
            $table->string('headline', 200);
            $table->text('what_happened');
            $table->text('why_it_matters');
            $table->text('background')->nullable();
            $table->jsonb('side_for');
            $table->jsonb('side_against');
            $table->text('question');
            $table->text('opening');
            $table->jsonb('key_words');
            // Briefs are written once and never edited, so there is no updated_at column.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['news_article_id', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debate_briefs');
    }
};
