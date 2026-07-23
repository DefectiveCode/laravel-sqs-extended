<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended\Tests;

use Aws\Result;
use Carbon\Carbon;
use Mockery\MockInterface;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow('1988-12-15 06:00:00');
    }

    /**
     * Allow the queue's MaximumMessageSize to be read, without requiring it.
     */
    protected function allowQueueMaxSize(MockInterface $sqs, string $queueUrl, int $maximumMessageSize = 262144): void
    {
        $sqs->shouldReceive('getQueueAttributes')
            ->with([
                'QueueUrl' => $queueUrl,
                'AttributeNames' => ['MaximumMessageSize'],
            ])
            ->andReturn(new Result([
                'Attributes' => ['MaximumMessageSize' => (string) $maximumMessageSize],
            ]));
    }
}
