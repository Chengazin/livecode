<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Set test-specific configurations
        config([
            'hashing.bcrypt.rounds' => 4, // Fast hashing for tests
            'captcha.provider' => 'disabled',
            'captcha.verify_on.registration_initiate' => false,
        ]);
    }
}
