<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The flow is fixed when the debate starts (App\Services\Guided\DebateStarter). Debates that predate the guided
     * flow were all classic, so the default applies to them. The level is the one the guided brief was prepared
     * for, so a level change in the middle of a debate does not change the brief it is built on.
     */
    public function up(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            $table->enum('flow', ['guided', 'classic'])->default('classic');
            $table->string('level', 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            $table->dropColumn(['flow', 'level']);
        });
    }
};
