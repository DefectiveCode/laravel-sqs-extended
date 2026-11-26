<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended\Tests;

use Mockery;
use ReflectionMethod;
use Aws\Sqs\SqsClient;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use DefectiveCode\LaravelSqsExtended\SqsDiskJob;
use DefectiveCode\LaravelSqsExtended\ResolvesPointers;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class ResolvesPointersTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testItReturnsNullWhenBodyIsEmpty(): void
    {
        $subject = $this->makeJob('');
        $this->assertNull($this->callResolvePointer($subject));
    }

    public function testItReturnsNullWhenBodyIsInvalidJson(): void
    {
        $subject = $this->makeJob('{');
        $this->assertNull($this->callResolvePointer($subject));
    }

    public function testItReturnsNullWhenPointerIsMissing(): void
    {
        $subject = $this->makeJob(json_encode((object) ['foo' => 'bar'], JSON_THROW_ON_ERROR));
        $this->assertNull($this->callResolvePointer($subject));
    }

    public function testItReturnsPointerStringWhenPresent(): void
    {
        $subject = $this->makeJob(json_encode((object) ['pointer' => 'manuals/a.pdf'], JSON_THROW_ON_ERROR));
        $this->assertSame('manuals/a.pdf', $this->callResolvePointer($subject));
    }

    public function testItCastsNumericPointerToString(): void
    {
        $subject = $this->makeJob(json_encode((object) ['pointer' => 12345], JSON_THROW_ON_ERROR));
        $this->assertSame('12345', $this->callResolvePointer($subject));
    }

    public function testItResolvesTheConfiguredDiskAdapter(): void
    {
        $subject = $this->makeSubject(['disk' => 'archive']);

        $manager = Mockery::mock(FilesystemManager::class);
        $adapter = Mockery::mock(FilesystemAdapter::class);

        $manager->shouldReceive('disk')
            ->once()
            ->with('archive')
            ->andReturn($adapter);

        $subject->container->instance('filesystem', $manager);

        $this->assertSame($adapter, $this->callResolveDisk($subject));
    }

    protected function makeJob(string $body): SqsDiskJob
    {
        return new SqsDiskJob(new Container, Mockery::mock(SqsClient::class), ['Body' => $body], 'connection', 'queue', ['disk' => 's3']);
    }

    /**
     * @param  array{disk: string}  $diskOptions
     * @return object{container: Container}
     */
    protected function makeSubject(array $diskOptions): object
    {
        return new class($diskOptions)
        {
            use ResolvesPointers;

            public Container $container;

            protected SqsClient $sqs;

            /**
             * @param  array{disk: string}  $diskOptions
             */
            public function __construct(public array $diskOptions)
            {
                $this->container = new Container;
            }
        };
    }

    protected function callResolvePointer(object $obj): ?string
    {
        $method = new ReflectionMethod($obj, 'resolvePointer');
        /** @var string|null $pointer */
        $pointer = $method->invoke($obj);

        return $pointer;
    }

    protected function callResolveDisk(object $obj): FilesystemAdapter
    {
        $method = new ReflectionMethod($obj, 'resolveDisk');
        /** @var FilesystemAdapter $disk */
        $disk = $method->invoke($obj);

        return $disk;
    }
}
