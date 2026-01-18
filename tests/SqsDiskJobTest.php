<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended\Tests;

use Mockery;
use Aws\Sqs\SqsClient;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemAdapter;
use DefectiveCode\LaravelSqsExtended\SqsDiskJob;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class SqsDiskJobTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private array $mockedJobData;

    private SqsClient $mockedSqsClient;

    private FilesystemAdapter $mockedFilesystemAdapter;

    private Container $mockedContainer;

    protected function setUp(): void
    {
        $mockedPayload = json_encode([
            'pointer' => 'prefix/e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81.json',
            'job' => 'App\\Jobs\\SomeJob',
        ]);
        $mockedMessageId = 'e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81';
        $mockedReceiptHandle = '0NNAq8PwvXuWv5gMtS9DJ8qEdyiUwbAjpp45w2m6M4SJ1Y+PxCh7R930NRB8ylSacEmoSnW18bgd4nK\/O6ctE+VFVul4eD23mA07vVoSnPI4F\/voI1eNCp6Iax0ktGmhlNVzBwaZHEr91BRtqTRM3QKd2ASF8u+IQaSwyl\/DGK+P1+dqUOodvOVtExJwdyDLy1glZVgm85Yw9Jf5yZEEErqRwzYz\/qSigdvW4sm2l7e4phRol\/+IjMtovOyH\/ukueYdlVbQ4OshQLENhUKe7RNN5i6bE\/e5x9bnPhfj2gbM';

        $this->mockedJobData = [
            'Body' => $mockedPayload,
            'MD5OfBody' => md5($mockedPayload),
            'ReceiptHandle' => $mockedReceiptHandle,
            'MessageId' => $mockedMessageId,
            'Attributes' => ['ApproximateReceiveCount' => 1],
        ];

        $this->mockedSqsClient = Mockery::mock(SqsClient::class);
        $this->mockedFilesystemAdapter = Mockery::mock(FilesystemAdapter::class);
        $this->mockedContainer = Mockery::mock(Container::class);
    }

    public function testItRemovesTheJobFromTheDiskIfCleanupIsEnabled(): void
    {
        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->once()
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('delete')
            ->with('prefix/e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81.json')
            ->once()
            ->andReturnSelf();

        $this->mockedContainer->shouldReceive('make')
            ->with('filesystem')
            ->once()
            ->andReturn($this->mockedFilesystemAdapter);

        $this->mockedSqsClient->shouldReceive('deleteMessage');

        $diskOptions = [
            'always_store' => true,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskJob = new SqsDiskJob(
            $this->mockedContainer,
            $this->mockedSqsClient,
            $this->mockedJobData,
            'connection',
            'queue',
            $diskOptions
        );

        $sqsDiskJob->delete();
    }

    public function testItLeavesTheJobOnTheDiskIfCleanupIsDisabled(): void
    {
        $this->mockedContainer->shouldReceive('make')
            ->with('filesystem')
            ->never();

        $this->mockedSqsClient->shouldReceive('deleteMessage')
            ->once();

        $diskOptions = [
            'always_store' => true,
            'cleanup' => false,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskJob = new SqsDiskJob(
            $this->mockedContainer,
            $this->mockedSqsClient,
            $this->mockedJobData,
            'connection',
            'queue',
            $diskOptions
        );

        $sqsDiskJob->delete();
    }

    public function testItReturnsTheRawBodyFromTheDiskIfAPointerExists(): void
    {
        $jobData = json_encode([
            'job' => 'job',
            'data' => ['data'],
            'attempts' => 1,
        ]);

        $this->mockedFilesystemAdapter->shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        $this->mockedFilesystemAdapter->shouldReceive('get')
            ->with('prefix/e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81.json')
            ->once()
            ->andReturn($jobData);

        $this->mockedContainer->shouldReceive('make')
            ->with('filesystem')
            ->andReturn($this->mockedFilesystemAdapter);

        $diskOptions = [
            'always_store' => true,
            'cleanup' => true,
            'disk' => 's3',
            'prefix' => 'prefix',
        ];

        $sqsDiskJob = new SqsDiskJob(
            $this->mockedContainer,
            $this->mockedSqsClient,
            $this->mockedJobData,
            'connection',
            'queue',
            $diskOptions
        );

        $this->assertEquals($jobData, $sqsDiskJob->getRawBody());
    }
}
