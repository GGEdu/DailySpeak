<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages render without a compiled Vite manifest (no `npm run build` needed for PHP tests).
        $this->withoutVite();
    }
}
