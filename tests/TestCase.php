<?php

namespace Tests;

use App\Services\News\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeHostResolver;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages render without a compiled Vite manifest (no `npm run build` needed for PHP tests).
        $this->withoutVite();

        // Feed and article hosts are checked before downloading; tests must never depend on real DNS.
        $this->app->instance(HostResolver::class, new FakeHostResolver);
    }
}
