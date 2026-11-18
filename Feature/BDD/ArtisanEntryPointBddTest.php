<?php

namespace Tests\Feature\BDD;

use Tests\TestCase;

class ArtisanEntryPointBddTest extends TestCase
{
    public function test_artisan_entry_point_uses_standard_laravel_bootstrap()
    {
        // bootstrap/app.php is the standard Laravel entry point (not bootstrap/kernel.php)
        $this->assertFileExists(__DIR__ . '/../../../bootstrap/app.php');

        // bootstrap/kernel.php is the stale Laravel-10 style kernel that should NOT exist
        $this->assertFileDoesNotExist(
            __DIR__ . '/../../../bootstrap/kernel.php',
            'Stale bootstrap/kernel.php should be removed; Laravel 13 uses bootstrap/app.php only'
        );
    }

    public function test_artisan_runs_correctly()
    {
        // Run artisan --version to verify the entry point works
        $output = shell_exec('php ' . escapeshellarg(base_path('artisan')) . ' --version 2>&1');

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('Framework', $output);
    }
}