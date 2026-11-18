<?php
declare(strict_types=1);
namespace Tests\Feature\BDD;
use Tests\TestCase;
class MinimalBddTest extends TestCase {
    #[Test]
    public function trivial(): void { $this->assertTrue(true); }
}
