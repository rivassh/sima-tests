<?php

namespace Tests\Units;

use PHPUnit\Framework\TestCase;
use App\Services\Integration\WorkflowAdapterInterface;
use App\Services\Integration\ProcessMakerV4Adapter;
use App\Services\Integration\Saniran\SyncAdapter;
use App\Services\Integration\Saniran\SaniranSyncAdapter;

class WorkflowAdapterTest extends TestCase
{
    public function testProcessMakerV4AdapterImplementsWorkflowAdapterInterface()
    {
        $adapter = new ProcessMakerV4Adapter('http://localhost:8000', 'test-token');

        $this->assertInstanceOf(WorkflowAdapterInterface::class, $adapter);
    }

    public function testProcessMakerV4AdapterHasRequiredMethods()
    {
        $adapter = new ProcessMakerV4Adapter('http://localhost:8000', 'test-token');

        $this->assertTrue(method_exists($adapter, 'startProcess'));
        $this->assertTrue(method_exists($adapter, 'getProcessStatus'));
        $this->assertTrue(method_exists($adapter, 'getActiveTasks'));
        $this->assertTrue(method_exists($adapter, 'getTask'));
        $this->assertTrue(method_exists($adapter, 'updateVariables'));
        $this->assertTrue(method_exists($adapter, 'completeTask'));
        $this->assertTrue(method_exists($adapter, 'getRequestFiles'));
        $this->assertTrue(method_exists($adapter, 'syncState'));
    }

    public function testProcessMakerV4AdapterStartsProcessWithEventNodeId()
    {
        $adapter = new ProcessMakerV4Adapter('http://localhost:8000', 'test-token');

        $method = new \ReflectionMethod($adapter, 'startProcess');
        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame('processId', $params[0]->getName());
        $this->assertSame('startEventNodeId', $params[1]->getName());
        $this->assertSame('variables', $params[2]->getName());
    }

    public function testSaniranSyncAdapterImplementsInterface()
    {
        $adapter = new SyncAdapter('http://localhost:8000', 'test-api-key');

        $this->assertInstanceOf(SaniranSyncAdapter::class, $adapter);
    }

    public function testSaniranSyncAdapterHasRequiredMethods()
    {
        $adapter = new SyncAdapter('http://localhost:8000', 'test-api-key');

        $this->assertTrue(method_exists($adapter, 'initialize'));
        $this->assertTrue(method_exists($adapter, 'synchronize'));
        $this->assertTrue(method_exists($adapter, 'bulkImport'));
        $this->assertTrue(method_exists($adapter, 'reconcile'));
    }

    public function testSaniranSyncAdapterInitializeMethod()
    {
        $adapter = new SyncAdapter('http://localhost:8000', 'test-api-key');
        $adapter->initialize(['base_url' => 'http://new-url:8080', 'api_key' => 'new-key']);

        $this->assertInstanceOf(SaniranSyncAdapter::class, $adapter);
    }
}