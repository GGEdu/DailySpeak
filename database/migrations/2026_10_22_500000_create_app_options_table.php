<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Text settings an admin sets in the panel (App\Services\AppOptions): the registration switch, the AI connection
     * and models, and the privacy notice's controller and contact. A key without a value uses its default in config/
     * or .env, so an empty table means every default. The AI key is stored encrypted (App\Services\AiSettings).
     */
    public function up(): void
    {
        Schema::create('app_options', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // The guided debate switch is gone: every new debate is guided, so its saved levels are no longer read.
        DB::table('app_settings')->where('key', 'like', 'guided_debate.%')->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_options');
    }
};
