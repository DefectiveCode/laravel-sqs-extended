<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended\Tests;

use Mockery;
use Aws\Result;
use ArrayObject;
use Aws\Command;
use Aws\Sqs\SqsClient;
use Mockery\MockInterface;
use UnexpectedValueException;
use Aws\Exception\AwsException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use DefectiveCode\LaravelSqsExtended\SqsDiskJob;
use DefectiveCode\LaravelSqsExtended\SqsDiskQueue;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class SqsDiskQueueTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $mockedMessageId;

    private string $mockedPayload;

    private string $mockedPointerPayload;

    private string $mockedLargePayload;

    private string $mockedReceiptHandle;

    private SqsClient&MockInterface $mockedSqsClient;

    private FilesystemAdapter&MockInterface $mockedFilesystemAdapter;

    private Container&MockInterface $mockedContainer;

    protected function setUp(): void
    {
        $this->mockedMessageId = 'e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81';
        $this->mockedPayload = json_encode(['job' => 'foo', 'data' => ['data'], 'uuid' => $this->mockedMessageId], JSON_THROW_ON_ERROR);
        $this->mockedPointerPayload = json_encode([
            'pointer' => 'prefix/e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81.json',
            'job' => 'foo',
        ], JSON_THROW_ON_ERROR);
        $this->mockedLargePayload = json_encode(['job' => 'foo', 'data' => [base64_encode(random_bytes(262144))], 'uuid' => $this->mockedMessageId], JSON_THROW_ON_ERROR);
        $this->mockedReceiptHandle = '0NNAq8PwvXuWv5gMtS9DJ8qEdyiUwbAjpp45w2m6M4SJ1Y+PxCh7R930NRB8ylSacEmoSnW18bgd4nK\/O6ctE+VFVul4eD23mA07vVoSnPI4F\/voI1eNCp6Iax0ktGmhlNVzBwaZHEr91BRtqTRM3QKd2ASF8u+IQaSwyl\/DGK+P1+dqUOodvOVtExJwdyDLy1glZVgm85Yw9Jf5yZEEErqRwzYz\/qSigdvW4sm2l7e4phRol\/+IjMtovOyH\/ukueYdlVbQ4OshQLENhUKe7RNN5i6bE\/e5x9bnPhfj2gbM';

        $this->mockedSqsClient = Mockery::mock(SqsClient::class);
        $this->mockedFilesystemAdapter = Mockery::mock(FilesystemAdapter::class);
        $this->mockedContainer = Mockery::mock(Container::class)->makePartial();

        $this->allowQueueMaxSize($this->mockedSqsClient, '/default');
    }

    public function testItDoesntPushToADiskIfTheAlwaysStoreIsDisabledAndThePayloadIsntLarge(): void
    {
        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->never();

        $this->mockedSqsClient->shouldReceive('sendMessage')
            ->with([
                'QueueUrl' => '/default',
                'MessageBody' => $this->mockedPayload,
            ])
            ->once()
            ->andReturnSelf();

        $this->mockedSqsClient->shouldReceive('get')
            ->once();

        $diskOptions = [
            'always_store' => false,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($this->mockedSqsClient, 'default', $diskOptions);
        $sqsDiskQueue->pushRaw($this->mockedPayload);
    }

    public function testItPushesLargePayloadsToADisk(): void
    {
        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('put')
            ->with('prefix/e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81.json', $this->mockedLargePayload)
            ->once();

        $this->mockedContainer->shouldReceive('make')
            ->with(Factory::class)
            ->andReturn($this->mockedFilesystemAdapter);

        $this->mockedSqsClient->shouldReceive('sendMessage')
            ->with([
                'QueueUrl' => '/default',
                'MessageBody' => $this->mockedPointerPayload,
            ])
            ->once()
            ->andReturnSelf();

        $this->mockedSqsClient->shouldReceive('get')
            ->once();

        $diskOptions = [
            'always_store' => false,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($this->mockedSqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->pushRaw($this->mockedLargePayload);
    }

    public function testItAlwaysPushesPayloadsToADiskIfAlwaysPushIsEnabled(): void
    {
        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('put')
            ->with('prefix/e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81.json', $this->mockedPayload)
            ->once();

        $this->mockedContainer->shouldReceive('make')
            ->with(Factory::class)
            ->andReturn($this->mockedFilesystemAdapter);

        $this->mockedSqsClient->shouldReceive('sendMessage')
            ->with([
                'QueueUrl' => '/default',
                'MessageBody' => $this->mockedPointerPayload,
            ])
            ->once()
            ->andReturnSelf();

        $this->mockedSqsClient->shouldReceive('get')
            ->once();

        $diskOptions = [
            'always_store' => true,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($this->mockedSqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->pushRaw($this->mockedPayload);
    }

    public function testItCanDelayAJobWhenPushedToDisk(): void
    {
        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('put')
            ->once();

        $this->mockedContainer->shouldReceive('make')
            ->with(Factory::class)
            ->andReturn($this->mockedFilesystemAdapter);

        $this->mockedSqsClient->shouldReceive('sendMessage')
            ->with(Mockery::on(function (array $arguments) {
                return $arguments['DelaySeconds'] === 10;
            }))
            ->once()
            ->andReturnSelf();

        $this->mockedSqsClient->shouldReceive('get')
            ->once();

        $diskOptions = [
            'always_store' => true,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($this->mockedSqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->later(10, 'foo');
    }

    public function testItCanDelayAJobWhenNotPushedToDisk(): void
    {
        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->never();

        $this->mockedSqsClient->shouldReceive('sendMessage')
            ->with(Mockery::on(function (array $arguments) {
                return $arguments['DelaySeconds'] === 10;
            }))
            ->once()
            ->andReturnSelf();

        $this->mockedSqsClient->shouldReceive('get')
            ->once();

        $diskOptions = [
            'always_store' => false,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($this->mockedSqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->later(10, 'foo');
    }

    public function testItDoesntPushBatchedPayloadsToADiskWhenTheyArentLarge(): void
    {
        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->never();

        $bodies = $this->captureBatchedMessageBodies();

        $diskOptions = [
            'always_store' => false,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($this->mockedSqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->bulk(['foo']);

        $this->assertCount(1, $bodies);
        $this->assertNotNull($bodies[0]);
        $decodedBody = json_decode($bodies[0], true);
        $this->assertIsArray($decodedBody);
        $this->assertEquals('foo', $decodedBody['job']);
        $this->assertArrayNotHasKey('pointer', $decodedBody);
    }

    public function testItPushesLargeBatchedPayloadsToADisk(): void
    {
        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('put')
            ->once();

        $this->mockedContainer->shouldReceive('make')
            ->with(Factory::class)
            ->andReturn($this->mockedFilesystemAdapter);

        $bodies = $this->captureBatchedMessageBodies();

        $diskOptions = [
            'always_store' => false,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($this->mockedSqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->bulk(['foo'], [base64_encode(random_bytes(262144))]);

        $this->assertCount(1, $bodies);

        $this->assertNotNull($bodies[0]);
        $decodedBody = json_decode($bodies[0], true);
        $this->assertIsArray($decodedBody);
        $this->assertEquals('foo', $decodedBody['job']);
        $this->assertNotNull($decodedBody['pointer']);
    }

    public function testItKeepsPayloadsInlineWhenTheQueueAllowsALargerMaximumMessageSize(): void
    {
        $sqsClient = Mockery::mock(SqsClient::class);
        $this->allowQueueMaxSize($sqsClient, '/default', 1048576);

        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->never();

        $sqsClient->shouldReceive('sendMessage')
            ->with([
                'QueueUrl' => '/default',
                'MessageBody' => $this->mockedLargePayload,
            ])
            ->once()
            ->andReturnSelf();

        $sqsClient->shouldReceive('get')
            ->once();

        $diskOptions = [
            'always_store' => false,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($sqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->pushRaw($this->mockedLargePayload);
    }

    public function testItPrefersTheConfiguredMaxSizeOverTheQueueAttribute(): void
    {
        $sqsClient = Mockery::mock(SqsClient::class);
        $sqsClient->shouldNotReceive('getQueueAttributes');

        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('put')
            ->with('prefix/e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81.json', $this->mockedPayload)
            ->once();

        $this->mockedContainer->shouldReceive('make')
            ->with(Factory::class)
            ->andReturn($this->mockedFilesystemAdapter);

        $sqsClient->shouldReceive('sendMessage')
            ->with([
                'QueueUrl' => '/default',
                'MessageBody' => $this->mockedPointerPayload,
            ])
            ->once()
            ->andReturnSelf();

        $sqsClient->shouldReceive('get')
            ->once();

        $diskOptions = [
            'always_store' => false,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
            'max_size' => 10,
        ];

        $sqsDiskQueue = new SqsDiskQueue($sqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->pushRaw($this->mockedPayload);
    }

    public function testItFallsBackToTheDefaultMaxLengthWhenTheQueueAttributeIsUnreadable(): void
    {
        $sqsClient = Mockery::mock(SqsClient::class);

        $sqsClient->shouldReceive('getQueueAttributes')
            ->once()
            ->andThrow(new AwsException('Access denied', new Command('GetQueueAttributes')));

        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('put')
            ->with('prefix/e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81.json', $this->mockedLargePayload)
            ->once();

        $this->mockedContainer->shouldReceive('make')
            ->with(Factory::class)
            ->andReturn($this->mockedFilesystemAdapter);

        $sqsClient->shouldReceive('sendMessage')
            ->with([
                'QueueUrl' => '/default',
                'MessageBody' => $this->mockedPointerPayload,
            ])
            ->once()
            ->andReturnSelf();

        $sqsClient->shouldReceive('get')
            ->once();

        $diskOptions = [
            'always_store' => false,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($sqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->pushRaw($this->mockedLargePayload);
    }

    public function testItFallsBackToTheDefaultMaxLengthWhenTheQueueOmitsTheAttribute(): void
    {
        $sqsClient = Mockery::mock(SqsClient::class);

        $sqsClient->shouldReceive('getQueueAttributes')
            ->once()
            ->andReturn(new Result);

        $this->mockedContainer->shouldReceive('make')
            ->never();

        $sqsClient->shouldReceive('sendMessage')
            ->with([
                'QueueUrl' => '/default',
                'MessageBody' => $this->mockedPayload,
            ])
            ->once()
            ->andReturnSelf();

        $sqsClient->shouldReceive('get')
            ->once();

        $sqsDiskQueue = new SqsDiskQueue($sqsClient, 'default', ['disk' => 's3', 'prefix' => 'prefix']);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->pushRaw($this->mockedPayload);
    }

    public function testItRefusesToStoreAPayloadWithoutAStringUuid(): void
    {
        $this->mockedSqsClient->shouldNotReceive('sendMessage');

        $sqsDiskQueue = new SqsDiskQueue($this->mockedSqsClient, 'default', ['always_store' => true, 'disk' => 's3']);

        $this->expectException(UnexpectedValueException::class);

        $sqsDiskQueue->pushRaw(json_encode(['job' => 'foo', 'uuid' => 123], JSON_THROW_ON_ERROR));
    }

    public function testItOnlyReadsTheQueueAttributeOncePerQueue(): void
    {
        $sqsClient = Mockery::mock(SqsClient::class);

        $sqsClient->shouldReceive('getQueueAttributes')
            ->once()
            ->andReturn(new Result([
                'Attributes' => ['MaximumMessageSize' => '262144'],
            ]));

        $sqsClient->shouldReceive('sendMessage')
            ->twice()
            ->andReturnSelf();

        $sqsClient->shouldReceive('get')
            ->twice();

        $diskOptions = [
            'always_store' => false,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($sqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->pushRaw($this->mockedPayload);
        $sqsDiskQueue->pushRaw($this->mockedPayload);
    }

    public function testItCreatesANewSqsDiskJobWhenPopped(): void
    {
        $this->mockedSqsClient->shouldReceive('receiveMessage')
            ->with([
                'QueueUrl' => '/default',
                'MessageSystemAttributeNames' => ['ApproximateReceiveCount'],
            ])
            ->once()
            ->andReturn([
                'Messages' => [
                    0 => [
                        'Body' => $this->mockedPayload,
                        'MD5OfBody' => md5($this->mockedPayload),
                        'ReceiptHandle' => $this->mockedReceiptHandle,
                        'MessageId' => $this->mockedMessageId,
                        'Attributes' => ['ApproximateReceiveCount' => '3'],
                    ],
                ],
            ]);

        $diskOptions = [
            'always_store' => true,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($this->mockedSqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $job = $sqsDiskQueue->pop();

        $this->assertInstanceOf(SqsDiskJob::class, $job);
        $this->assertSame(3, $job->attempts());
    }

    public function testItClearsTheDiskWhenQueueisCleared(): void
    {
        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('deleteDirectory')
            ->with('prefix')
            ->once();

        $this->mockedContainer->shouldReceive('make')
            ->with(Factory::class)
            ->andReturn($this->mockedFilesystemAdapter);

        $this->mockedSqsClient->shouldReceive('getQueueAttributes')
            ->andReturn(new Result([
                'Attributes' => [
                    'ApproximateNumberOfMessages' => 1,
                    'ApproximateNumberOfMessagesDelayed' => 0,
                    'ApproximateNumberOfMessagesNotVisible' => 0,
                ],
            ]));

        $this->mockedSqsClient->shouldReceive('purgeQueue');

        $diskOptions = [
            'always_store' => true,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskQueue = new SqsDiskQueue($this->mockedSqsClient, 'default', $diskOptions);
        $sqsDiskQueue->setContainer($this->mockedContainer);
        $sqsDiskQueue->clear('default');
    }

    /**
     * Collect the bodies bulk() sends, whichever SQS API the framework reaches for.
     *
     * Laravel dispatches batches through SendMessageBatch from 13.19 onward, and
     * one SendMessage per job before that.
     *
     * @return ArrayObject<int, string>
     */
    protected function captureBatchedMessageBodies(): ArrayObject
    {
        /** @var ArrayObject<int, string> $bodies */
        $bodies = new ArrayObject;

        $this->mockedSqsClient->shouldReceive('sendMessage')
            ->with(Mockery::on(function (array $arguments) use ($bodies) {
                /** @var array{MessageBody: string} $arguments */
                $bodies->append($arguments['MessageBody']);

                return true;
            }))
            ->andReturnSelf();

        $this->mockedSqsClient->shouldReceive('get')
            ->with('MessageId')
            ->andReturn('message-id');

        $this->mockedSqsClient->shouldReceive('sendMessageBatch')
            ->with(Mockery::on(function (array $arguments) use ($bodies) {
                /** @var list<array{MessageBody: string}> $entries */
                $entries = $arguments['Entries'];

                foreach ($entries as $entry) {
                    $bodies->append($entry['MessageBody']);
                }

                return true;
            }))
            ->andReturn(new Result(['Successful' => []]));

        return $bodies;
    }
}
