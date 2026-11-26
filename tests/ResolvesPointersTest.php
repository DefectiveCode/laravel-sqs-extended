<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended\Tests;

use Mockery;
use ReflectionMethod;
use Aws\Sqs\SqsClient;
use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use DefectiveCode\LaravelSqsExtended\SqsDiskJob;
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
        $manager = Mockery::mock(Factory::class);
        $disk = Mockery::mock(Filesystem::class);

        $manager->shouldReceive('disk')
            ->once()
            ->with('archive')
            ->andReturn($disk);

        $container = new Container;
        $container->instance(Factory::class, $manager);

        $subject = $this->makeJob('{}', $container, ['disk' => 'archive']);

        $this->assertSame($disk, $this->callResolveDisk($subject));
    }

    /**
     * @param  array{disk: string}  $diskOptions
     */
    protected function makeJob(string $body, Container $container = new Container, array $diskOptions = ['disk' => 's3']): SqsDiskJob
    {
        return new SqsDiskJob($container, Mockery::mock(SqsClient::class), ['Body' => $body], 'connection', 'queue', $diskOptions);
    }

    protected function callResolvePointer(object $obj): ?string
    {
        $method = new ReflectionMethod($obj, 'resolvePointer');
        /** @var string|null $pointer */
        $pointer = $method->invoke($obj);

        return $pointer;
    }

    protected function callResolveDisk(object $obj): Filesystem
    {
        $method = new ReflectionMethod($obj, 'resolveDisk');
        /** @var Filesystem $disk */
        $disk = $method->invoke($obj);

        return $disk;
    }
}
