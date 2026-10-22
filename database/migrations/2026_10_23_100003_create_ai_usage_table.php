<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per AI call, without any content: what it was for, with whom, who paid, the size (tokens, characters or
     * seconds, when the provider reports them) and whether it worked. user_id is null for the common work (the news
     * harvest) and when the account is erased, so the platform's totals survive it.
     */
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task', 16);
            $table->string('provider', 32);
            $table->string('model', 120)->nullable();
            $table->string('paid_by', 8);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('characters')->nullable();
            $table->float('seconds')->nullable();
            $table->boolean('ok');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
