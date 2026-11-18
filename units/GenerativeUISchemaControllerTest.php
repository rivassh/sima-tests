<?php

namespace Tests\Units;

use PHPUnit\Framework\TestCase;
use App\Http\Controllers\GenerativeUI\GenerativeUISchemaController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GenerativeUISchemaControllerTest extends TestCase
{
    private GenerativeUISchemaController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new GenerativeUISchemaController();
    }

    public function testDashboardSchemaReturnsJsonResponse()
    {
        $request = Request::create('/api/v1/ui/dashboard-schema', 'GET');
        $response = $this->controller->dashboardSchema($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('schema', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testDashboardSchemaContainsExpectedWidgets()
    {
        $request = Request::create('/api/v1/ui/dashboard-schema', 'GET');
        $response = $this->controller->dashboardSchema($request);
        $data = json_decode($response->getContent(), true);

        $schema = $data['schema'];
        $this->assertEquals('container', $schema['type']);
        $this->assertEquals('Talent Acquisition Dashboard', $schema['title']);
        $this->assertNotEmpty($schema['children']);
    }

    public function testSchemaVersionIsCorrect()
    {
        $request = Request::create('/api/v1/ui/dashboard-schema', 'GET');
        $response = $this->controller->dashboardSchema($request);
        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('version', $data);
        $this->assertIsString($data['version']);
        $this->assertNotEmpty($data['version']);
    }
}