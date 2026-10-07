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
        Schema::table('news_articles', function (Blueprint $table) {
            // Nullable: articles harvested with `news:fetch --feed=` or before sources existed have none.
            $table->foreignId('news_source_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index('news_source_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('news_articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('news_source_id');
        });
    }
};
