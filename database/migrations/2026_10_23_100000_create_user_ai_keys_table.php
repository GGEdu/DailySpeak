<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A user's own API key for one provider of the closed list (App\Services\Ai\ProviderCatalog). The key is stored
     * encrypted (the model's encrypted cast) and is never returned: only its last four characters are shown.
     */
    public function up(): void
    {
        Schema::create('user_ai_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->text('key');
            $table->string('last4', 4);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_ai_keys');
    }
};
