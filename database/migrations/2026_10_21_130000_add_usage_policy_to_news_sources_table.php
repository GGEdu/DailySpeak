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
        Schema::table('news_sources', function (Blueprint $table) {
            // Result of the usage-policy check (see SourceUsagePolicy): ok, warning or unknown. Null until checked.
            $table->string('policy_status', 16)->nullable();
            $table->text('policy_note')->nullable();
            $table->timestamp('policy_checked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('news_sources', function (Blueprint $table) {
            $table->dropColumn(['policy_status', 'policy_note', 'policy_checked_at']);
        });
    }
};
