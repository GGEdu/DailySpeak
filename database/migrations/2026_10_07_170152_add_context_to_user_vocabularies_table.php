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
            // Example sentence from the fluency report, shown when reviewing the word.
            $table->text('context')->nullable()->after('word');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_vocabularies', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
