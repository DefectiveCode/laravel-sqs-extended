<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended\Tests;

use Mockery;
use ReflectionMethod;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Illuminate\Contracts\Container\Container as ContainerContract;

class ResolvesPointersTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testItReturnsNullWhenBodyIsEmpty(): void
    {
        $subject = $this->makeSubject(['Body' => '']);
        $this->assertNull($this->callResolvePointer($subject));
    }

    public function testItReturnsNullWhenBodyIsInvalidJson(): void
    {
        $subject = $this->makeSubject(['Body' => '{']);
        $this->assertNull($this->callResolvePointer($subject));
    }

    public function testItReturnsNullWhenPointerIsMissing(): void
    {
        $subject = $this->makeSubject(['Body' => json_encode((object) ['foo' => 'bar'])]);
        $this->assertNull($this->callResolvePointer($subject));
    }

    public function testItReturnsPointerStringWhenPresent(): void
    {
        $subject = $this->makeSubject(['Body' => json_encode((object) ['pointer' => 'manuals/a.pdf'])]);
        $this->assertSame('manuals/a.pdf', $this->callResolvePointer($subject));
    }

    public function testItCastsNumericPointerToString(): void
    {
        $subject = $this->makeSubject(['Body' => json_encode((object) ['pointer' => 12345])]);
        $this->assertSame('12345', $this->callResolvePointer($subject));
    }

    public function testItResolvesTheConfiguredDiskAdapter(): void
    {
        $subject = $this->makeSubject(['Body' => '{}'], ['disk' => 'archive']);

        $manager = Mockery::mock(FilesystemManager::class);
        $adapter = Mockery::mock(FilesystemAdapter::class);

        $manager->shouldReceive('disk')
            ->once()
            ->with('archive')
            ->andReturn($adapter);

        $subject->container->instance('filesystem', $manager);

        $this->assertSame($adapter, $this->callResolveDisk($subject));
    }

    protected function makeSubject(array $job, array $diskOptions = ['disk' => 's3'])
    {
        return new class($job, $diskOptions)
        {
            use \DefectiveCode\LaravelSqsExtended\ResolvesPointers;

            public ContainerContract $container;

            public function __construct(public array $job, public array $diskOptions)
            {
                $this->container = new Container;
            }
        };
    }

    protected function callResolvePointer(object $obj): ?string
    {
        $method = new ReflectionMethod($obj, 'resolvePointer');
        $method->setAccessible(true);

        return $method->invoke($obj);
    }

    protected function callResolveDisk(object $obj): FilesystemAdapter
    {
        $method = new ReflectionMethod($obj, 'resolveDisk');
        $method->setAccessible(true);

        return $method->invoke($obj);
    }
}
