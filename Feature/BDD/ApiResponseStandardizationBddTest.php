<?php

declare(strict_types=1);

namespace Tests\Feature\BDD;

use App\Http\Responses\ApiResponse;
use Tests\TestCase;

class ApiResponseStandardizationBddTest extends TestCase
{
    public function test_api_response_success_returns_correct_structure(): void
    {
        $response = ApiResponse::success(['name' => 'John'], 'User created');
        
        $this->assertEquals(200, $response->status());
        $this->assertTrue($response->json('success'));
        $this->assertEquals('User created', $response->json('message'));
        $this->assertEquals(['name' => 'John'], $response->json('data'));
        $this->assertNull($response->json('error'));
    }

    public function test_api_response_success_without_message(): void
    {
        $response = ApiResponse::success(['id' => 1]);
        
        $this->assertTrue($response->json('success'));
        $this->assertNull($response->json('message'));
    }

    public function test_api_response_error_returns_correct_structure(): void
    {
        $response = ApiResponse::error('Not found', 404);
        
        $this->assertEquals(404, $response->status());
        $this->assertFalse($response->json('success'));
        $this->assertEquals('Not found', $response->json('error'));
        $this->assertNull($response->json('data'));
        $this->assertNull($response->json('message'));
    }

    public function test_api_response_error_with_details(): void
    {
        $response = ApiResponse::error('Validation failed', 422, ['field' => 'email', 'message' => 'Invalid email']);
        
        $this->assertEquals(422, $response->status());
        $this->assertFalse($response->json('success'));
        $this->assertEquals('Validation failed', $response->json('error'));
        $this->assertEquals(['field' => 'email', 'message' => 'Invalid email'], $response->json('details'));
    }

    public function test_api_response_paginate_returns_correct_structure(): void
    {
        $items = [['id' => 1], ['id' => 2]];
        $response = ApiResponse::paginate($items, 10, 1, 2);
        
        $this->assertEquals(200, $response->status());
        $this->assertTrue($response->json('success'));
        $this->assertEquals($items, $response->json('data'));
        $this->assertEquals([
            'total' => 10,
            'page' => 1,
            'per_page' => 2,
            'last_page' => 5,
        ], $response->json('meta'));
    }

    public function test_api_response_paginate_calculates_last_page(): void
    {
        $items = [['id' => 1], ['id' => 2], ['id' => 3]];
        $response = ApiResponse::paginate($items, 7, 1, 3);
        
        $meta = $response->json('meta');
        $this->assertEquals(3, $meta['last_page']);
        
        $response2 = ApiResponse::paginate($items, 10, 2, 3);
        $meta2 = $response2->json('meta');
        $this->assertEquals(4, $meta2['last_page']);
    }

    public function test_api_response_error_with_empty_details(): void
    {
        $response = ApiResponse::error('Server error', 500, []);
        
        $this->assertEquals(500, $response->status());
        $this->assertFalse($response->json('success'));
        $this->assertEquals([], $response->json('details'));
    }
}