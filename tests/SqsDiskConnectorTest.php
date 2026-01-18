<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended\Tests;

use DefectiveCode\LaravelSqsExtended\SqsDiskQueue;
use DefectiveCode\LaravelSqsExtended\SqsDiskConnector;

class SqsDiskConnectorTest extends TestCase
{
    public function testItCreatesSqsDiskQueueInstance(): void
    {
        $connector = new SqsDiskConnector;

        $config = [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'queue' => 'default',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
            'suffix' => '',
            'after_commit' => false,
            'disk_options' => [
                'always_store' => false,
                'cleanup' => true,
                'disk' => 's3',
                'prefix' => 'queue-payloads',
            ],
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(SqsDiskQueue::class, $queue);
    }
}
