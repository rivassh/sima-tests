<?php

namespace Tests\Unit;

use Tests\TestCase;

class BrandIdentityTest extends TestCase
{
    public function test_public_pages_and_responsive_brand_assets_use_the_persian_brand(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $brand = 'ســـیــمـا';

        foreach ([
            'resources/views/chat.blade.php',
            'resources/views/dashboard.blade.php',
        ] as $view) {
            $contents = file_get_contents($projectRoot.'/'.$view);

            $this->assertIsString($contents);
            $this->assertStringContainsString($brand, $contents);
            $this->assertStringContainsString('/images/brand/mark.svg', $contents);
            $this->assertStringContainsString('/images/brand/icon-32.png', $contents);
        }

        $manifestContents = file_get_contents($projectRoot.'/public/site.webmanifest');

        $this->assertIsString($manifestContents);
        $manifest = json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($brand, $manifest['name']);

        foreach (['icon-16.png', 'icon-32.png', 'icon-180.png', 'icon-192.png', 'icon-512.png'] as $icon) {
            $this->assertFileExists($projectRoot.'/public/images/brand/'.$icon);
        }

        $this->assertFileExists($projectRoot.'/public/favicon.ico');
    }
}
