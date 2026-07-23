<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended\Tests;

use Mockery;
use Aws\Sqs\SqsClient;
use Mockery\MockInterface;
use Illuminate\Container\Container;
use Laravel\Vapor\Queue\JobAttempts;
use League\Flysystem\UnableToWriteFile;
use Illuminate\Filesystem\FilesystemAdapter;
use DefectiveCode\LaravelSqsExtended\SqsDiskQueue;
use DefectiveCode\LaravelSqsExtended\VaporSqsDiskJob;
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

    public function testItReleasesAnOversizedPayloadAsAPointer(): void
    {
        $payload = json_encode([
            'uuid' => 'release-uuid-123',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['command' => base64_encode(random_bytes(262144))],
        ]);

        $storedPayload = null;

        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('get')
            ->with('queue-jobs/release-uuid-123.json')
            ->once()
            ->andReturn($payload);

        $this->mockedFilesystemAdapter->shouldReceive('put')
            ->with('queue-jobs/release-uuid-123.json', Mockery::on(function ($contents) use (&$storedPayload) {
                $storedPayload = $contents;

                return true;
            }))
            ->once();

        $this->mockedContainer->shouldReceive('make')
            ->with('filesystem')
            ->andReturn($this->mockedFilesystemAdapter);

        $this->expectJobAttemptsTransfer();
        $this->expectReleaseSqsCalls();

        $this->createJob(json_encode([
            'pointer' => 'queue-jobs/release-uuid-123.json',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        ]))->release();

        $decodedBody = json_decode($this->capturedMessageBody);

        $this->assertEquals('queue-jobs/release-uuid-123.json', $decodedBody->pointer);
        $this->assertEquals('Illuminate\\Queue\\CallQueuedHandler@call', $decodedBody->job);
        $this->assertLessThan(VaporSqsDiskJob::MAX_SQS_LENGTH, strlen($this->capturedMessageBody));
        $this->assertEquals(1, json_decode($storedPayload)->attempts);
    }

    public function testItReleasesASmallPayloadInline(): void
    {
        $payload = json_encode([
            'uuid' => 'release-uuid-456',
            'job' => 'App\\Jobs\\SmallJob',
            'data' => ['key' => 'value'],
        ]);

        $this->mockedContainer->shouldReceive('make')
            ->with('filesystem')
            ->never();

        $this->expectJobAttemptsTransfer();
        $this->expectReleaseSqsCalls();

        $this->createJob($payload)->release();

        $decodedBody = json_decode($this->capturedMessageBody);

        $this->assertEquals('release-uuid-456', $decodedBody->uuid);
        $this->assertEquals(['key' => 'value'], (array) $decodedBody->data);
        $this->assertEquals(1, $decodedBody->attempts);
    }

    public function testItDoesNotDeleteTheMessageWhenTheDiskWriteFails(): void
    {
        $payload = json_encode([
            'uuid' => 'release-uuid-789',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['command' => base64_encode(random_bytes(262144))],
        ]);

        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('get')
            ->with('queue-jobs/release-uuid-789.json')
            ->once()
            ->andReturn($payload);

        $this->mockedFilesystemAdapter->shouldReceive('put')
            ->once()
            ->andReturnFalse();

        $this->mockedContainer->shouldReceive('make')
            ->with('filesystem')
            ->andReturn($this->mockedFilesystemAdapter);

        $this->expectJobAttemptsGet();

        $this->mockedSqsClient->shouldNotReceive('deleteMessage');
        $this->mockedSqsClient->shouldNotReceive('sendMessage');

        $this->expectException(UnableToWriteFile::class);

        $this->createJob(json_encode([
            'pointer' => 'queue-jobs/release-uuid-789.json',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        ]))->release();
    }

    protected function expectReleaseSqsCalls(): void
    {
        $this->mockedSqsClient->shouldReceive('deleteMessage')
            ->once();

        $this->mockedSqsClient->shouldReceive('sendMessage')
            ->with(Mockery::on(function ($arguments) {
                $this->capturedMessageBody = $arguments['MessageBody'];

                return true;
            }))
            ->once()
            ->andReturnSelf();

        $this->mockedSqsClient->shouldReceive('get')
            ->with('MessageId')
            ->once()
            ->andReturn('new-message-id');
    }

    protected function expectJobAttemptsTransfer(): void
    {
        $this->expectJobAttemptsGet()
            ->shouldReceive('transfer')
            ->with(Mockery::type(VaporSqsDiskJob::class), 'new-message-id')
            ->once();
    }

    protected function expectJobAttemptsGet(): MockInterface
    {
        $jobAttempts = Mockery::mock(JobAttempts::class);

        $jobAttempts->shouldReceive('get')
            ->andReturn(0);

        $this->mockedContainer->shouldReceive('make')
            ->with(JobAttempts::class)
            ->andReturn($jobAttempts);

        return $jobAttempts;
    }

    protected function createJob(string $body): VaporSqsDiskJob
    {
        $job = [
            'Body' => $body,
            'MD5OfBody' => md5($body),
            'ReceiptHandle' => 'receipt-handle',
            'MessageId' => 'e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81',
            'Attributes' => ['ApproximateReceiveCount' => 1],
        ];

        return new VaporSqsDiskJob(
            $this->mockedContainer,
            $this->mockedSqsClient,
            $job,
            'connection',
            'queue',
            [
                'always_store' => false,
                'cleanup' => true,
                'disk' => 's3',
                'prefix' => 'queue-jobs',
            ]
        );
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
