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
        Schema::table('user_vocabularies', function (Blueprint $table) {
            // Filled by AnalyzeVocabulary: the translation into the learner's language, and the
            // part of speech, definition, example and synonyms shown when reviewing the word.
            $table->string('translation')->nullable()->after('context');
            $table->jsonb('analysis')->nullable()->after('translation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_vocabularies', function (Blueprint $table) {
            $table->dropColumn(['translation', 'analysis']);
        });
    }
};
