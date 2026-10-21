<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The turn a tutor reply answers. Adding a nullable column with a unique index is safe for existing rows:
     * they are all NULL, and PostgreSQL lets NULLs repeat under a unique index. So only new replies are linked.
     */
    public function up(): void
    {
        Schema::table('debate_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_id')->nullable()->unique()->constrained('debate_messages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debate_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
        });
    }
};
