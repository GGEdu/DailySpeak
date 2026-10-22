<?php

namespace Tests\Feature\Services;

use App\Models\User;
use App\Services\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_saved_value_every_limit_is_the_configured_default(): void
    {
        $limits = app(Limits::class);

        $this->assertSame([
            'ai_daily_global' => 1000,
            'registrations_per_ip_per_day' => 10,
            'voice_turns_per_minute' => 20,
            'voice_turns_per_day' => 300,
            'lookups_per_minute' => 40,
            'lookups_per_day' => 500,
            'feed_searches_per_minute' => 30,
            'debate_finishes_per_minute' => 5,
        ], collect(Limits::keys())->mapWithKeys(fn (string $key) => [$key => $limits->value($key)])->all());
        $this->assertDatabaseEmpty('app_settings');
    }

    public function test_before_the_migration_has_run_the_defaults_apply_and_nothing_is_cached(): void
    {
        // A deploy serves the new code for a minute before it migrates: no limiter may fail for want of the table.
        Schema::drop('app_settings');

        $this->assertSame(config('limits.ai_daily_global'), app(Limits::class)->value('ai_daily_global'));
        $this->assertFalse(Cache::has('app-settings:limits'), 'An empty answer must not be cached, or the saved values would never be read.');
    }

    public function test_a_default_is_read_from_the_config_when_it_is_needed(): void
    {
        config(['limits.lookups_per_minute' => 7]);

        $this->assertSame(7, app(Limits::class)->value('lookups_per_minute'));
    }

    public function test_a_saved_value_replaces_the_default_until_it_is_reset(): void
    {
        $admin = User::factory()->admin()->create();
        $limits = app(Limits::class);

        $limits->save('lookups_per_minute', 12, $admin);

        $this->assertSame(12, $limits->value('lookups_per_minute'));
        $this->assertSame(40, $limits->default('lookups_per_minute'));

        $limits->resetToDefault('lookups_per_minute', $admin);

        $this->assertSame(40, $limits->value('lookups_per_minute'));
        $this->assertDatabaseMissing('app_settings', ['key' => 'lookups_per_minute']);
    }

    public function test_a_saved_value_records_which_admin_changed_it(): void
    {
        $admin = User::factory()->admin()->create();

        app(Limits::class)->save('ai_daily_global', 500, $admin);

        $this->assertDatabaseHas('app_settings', ['key' => 'ai_daily_global', 'value' => 500, 'updated_by' => $admin->id]);
    }

    public function test_reads_come_from_the_cache_until_a_limit_is_saved(): void
    {
        $admin = User::factory()->admin()->create();
        $limits = app(Limits::class);
        $limits->value('ai_daily_global'); // Fills the cache.

        DB::enableQueryLog();
        $limits->value('ai_daily_global');
        $limits->value('lookups_per_day');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries);

        // The saved value shows at once: saving forgets the cached copy.
        $limits->save('ai_daily_global', 42, $admin);

        $this->assertSame(42, $limits->value('ai_daily_global'));
    }

    public function test_every_change_is_logged_with_the_admin_the_key_and_the_values_only(): void
    {
        Log::spy();
        $admin = User::factory()->admin()->create(['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
        $limits = app(Limits::class);

        $limits->save('lookups_per_day', 250, $admin);
        $limits->resetToDefault('lookups_per_day', $admin);

        Log::shouldHaveReceived('info', ['Limit changed by an admin', [
            'admin_id' => $admin->id,
            'key' => 'lookups_per_day',
            'before' => 500,
            'after' => 250,
        ]])->once();
        Log::shouldHaveReceived('info', ['Limit changed by an admin', [
            'admin_id' => $admin->id,
            'key' => 'lookups_per_day',
            'before' => 250,
            'after' => 500,
        ]])->once();
    }

    public function test_the_login_and_email_limits_are_not_managed(): void
    {
        // Sign-in and email-verification limits are security limits: they stay in code.
        $this->assertSame([
            'ai_daily_global',
            'registrations_per_ip_per_day',
            'voice_turns_per_minute',
            'voice_turns_per_day',
            'lookups_per_minute',
            'lookups_per_day',
            'feed_searches_per_minute',
            'debate_finishes_per_minute',
        ], Limits::keys());
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $this->assertFalse(Limits::has('login_per_minute'));
        $this->expectException(\InvalidArgumentException::class);

        app(Limits::class)->value('login_per_minute');
    }
}
