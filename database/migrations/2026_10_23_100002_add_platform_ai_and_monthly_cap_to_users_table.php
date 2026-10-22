<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * platform_ai: whether the user may use the platform's AI connection (admins always may). ai_monthly_cap: the AI
     * calls the user allows themselves in a calendar month; null means no cap.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('platform_ai')->default(false);
            $table->unsignedInteger('ai_monthly_cap')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['platform_ai', 'ai_monthly_cap']);
        });
    }
};
