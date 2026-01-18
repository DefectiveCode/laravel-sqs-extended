<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended\Tests;

use Mockery;
use Aws\Sqs\SqsClient;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemAdapter;
use DefectiveCode\LaravelSqsExtended\SqsDiskQueue;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class VaporSqsDiskJobTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected SqsClient $mockedSqsClient;

    protected FilesystemAdapter $mockedFilesystemAdapter;

    protected Container $mockedContainer;

    protected ?string $capturedMessageBody = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockedSqsClient = Mockery::mock(SqsClient::class);
        $this->mockedFilesystemAdapter = Mockery::mock(FilesystemAdapter::class);
        $this->mockedContainer = Mockery::mock(Container::class)->makePartial();
    }

    public function testOldPointerFormatFailsVaporDetection(): void
    {
        $payload = json_encode(['pointer' => 'prefix/uuid.json']);

        $this->assertFalse($this->simulateVaporQueueDetection($payload));
    }

    public function testNewPointerFormatPassesVaporDetection(): void
    {
        $payload = json_encode([
            'pointer' => 'prefix/uuid.json',
            'job' => 'App\\Jobs\\ProcessPodcast',
        ]);

        $this->assertTrue($this->simulateVaporQueueDetection($payload));
    }

    public function testRegularPayloadPassesVaporDetection(): void
    {
        $payload = json_encode([
            'uuid' => 'some-uuid',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['command' => 'serialized-command'],
        ]);

        $this->assertTrue($this->simulateVaporQueueDetection($payload));
    }

    public function testLargePayloadIncludesJobPropertyForVaporDetection(): void
    {
        $this->setUpDiskStorageMocks();

        $payload = json_encode([
            'uuid' => 'test-uuid-123',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['command' => base64_encode(random_bytes(262144))],
        ]);

        $this->createQueue()->pushRaw($payload);

        $this->assertTrue($this->simulateVaporQueueDetection($this->capturedMessageBody));

        $decodedBody = json_decode($this->capturedMessageBody);
        $this->assertEquals('queue-jobs/test-uuid-123.json', $decodedBody->pointer);
        $this->assertEquals('Illuminate\\Queue\\CallQueuedHandler@call', $decodedBody->job);
    }

    public function testAlwaysStoreIncludesJobProperty(): void
    {
        $this->setUpDiskStorageMocks();

        $payload = json_encode([
            'uuid' => 'small-uuid-456',
            'job' => 'App\\Jobs\\SmallJob',
            'data' => ['key' => 'value'],
        ]);

        $this->createQueue(alwaysStore: true)->pushRaw($payload);

        $this->assertTrue($this->simulateVaporQueueDetection($this->capturedMessageBody));

        $decodedBody = json_decode($this->capturedMessageBody);
        $this->assertNotNull($decodedBody->pointer);
        $this->assertEquals('App\\Jobs\\SmallJob', $decodedBody->job);
    }

    protected function simulateVaporQueueDetection(string $body): bool
    {
        $messageId = 'test-message-id';
        $job = json_decode($body)->job ?? null;

        return $messageId && $job;
    }

    protected function setUpDiskStorageMocks(): void
    {
        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('put')
            ->once();

        $this->mockedContainer->shouldReceive('make')
            ->with('filesystem')
            ->andReturn($this->mockedFilesystemAdapter);

        $this->mockedSqsClient->shouldReceive('sendMessage')
            ->with(Mockery::on(function ($arguments) {
                $this->capturedMessageBody = $arguments['MessageBody'];

                return true;
            }))
            ->once()
            ->andReturnSelf();

        $this->mockedSqsClient->shouldReceive('get')
            ->once();
    }

    protected function createQueue(bool $alwaysStore = false): SqsDiskQueue
    {
        $diskOptions = [
            'always_store' => $alwaysStore,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'queue-jobs',
        ];

        $queue = new SqsDiskQueue($this->mockedSqsClient, 'default', $diskOptions);
        $queue->setContainer($this->mockedContainer);

        return $queue;
    }
}
