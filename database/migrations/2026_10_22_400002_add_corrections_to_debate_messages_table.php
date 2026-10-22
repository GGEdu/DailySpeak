<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Written corrections of a learner's turn. Null until the turn is reviewed (and for the tutor's messages), an
     * empty list when nothing needed correcting or the review was not possible. Each entry keeps its "dismissed" flag
     * (the learner's "I did not say that"), so the report can leave it out.
     */
    public function up(): void
    {
        Schema::table('debate_messages', function (Blueprint $table) {
            $table->jsonb('corrections')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debate_messages', function (Blueprint $table) {
            $table->dropColumn('corrections');
        });
    }
};
