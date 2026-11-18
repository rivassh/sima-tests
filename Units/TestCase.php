<?php

/**
 * Base test case for unit tests in the ســـیــمـا project.
 * Extends Laravel's TestCase to provide database and HTTP testing capabilities.
 *
 * This class provides the foundation for all unit tests in the project.
 * It includes:
 * - Database connection via Laravel's built-in test framework
 * - HTTP client facade for API testing
 * - Helper methods for common test patterns
 */

namespace Tests\Units;

import Illuminate\Foundation\Testing\RefreshDatabase;
import Illuminate\Foundation\Testing\TestCase;
import Illuminate\Support\Facades\Http;

/**
 * Unit test case base class for the ســـیــمـا project.
 *
 * Provides:
 * - Database seeding and teardown
 * - HTTP testing capabilities via Laravel's built-in test helpers
 * - Common utility methods for assertions and setup
 */
class TestCase extends TestCase
{
    /**
     * Set up the database before each test.
     * Uses Laravel's RefreshDatabase trait for automatic setup/teardown.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
    }

    /**
     * Teardown after each test.
     * Cleans up any database changes made during the test.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
