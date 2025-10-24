<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended\Tests;

use Mockery;
use ReflectionMethod;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;

final class ResolvesPointersTest extends TestCase
{
    private function makeSubject(array $job, array $diskOptions = ['disk' => 's3'])
    {
        return new class ($job, $diskOptions) {
            use \DefectiveCode\LaravelSqsExtended\ResolvesPointers;

            public ContainerContract $container;

            public function __construct(public array $job, public array $diskOptions)
            {
                $this->container = new Container();
            }
        };
    }

    public function test_it_returns_null_when_body_is_empty(): void
    {
        $subject = $this->makeSubject(['Body' => '']);
        $this->assertNull($this->callResolvePointer($subject));
    }

    public function test_it_returns_null_when_body_is_invalid_json(): void
    {
        $subject = $this->makeSubject(['Body' => '{']);
        $this->assertNull($this->callResolvePointer($subject));
    }

    public function test_it_returns_null_when_pointer_is_missing(): void
    {
        $subject = $this->makeSubject(['Body' => json_encode((object) ['foo' => 'bar'])]);
        $this->assertNull($this->callResolvePointer($subject));
    }

    public function test_it_returns_pointer_string_when_present(): void
    {
        $subject = $this->makeSubject(['Body' => json_encode((object) ['pointer' => 'manuals/a.pdf'])]);
        $this->assertSame('manuals/a.pdf', $this->callResolvePointer($subject));
    }

    public function test_it_casts_numeric_pointer_to_string(): void
    {
        $subject = $this->makeSubject(['Body' => json_encode((object) ['pointer' => 12345])]);
        $this->assertSame('12345', $this->callResolvePointer($subject));
    }

    public function test_it_resolves_the_configured_disk_adapter(): void
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

    private function callResolvePointer(object $obj): ?string
    {
        $method = new ReflectionMethod($obj, 'resolvePointer');
        $method->setAccessible(true);

        return $method->invoke($obj);
    }

    private function callResolveDisk(object $obj): FilesystemAdapter
    {
        $method = new ReflectionMethod($obj, 'resolveDisk');
        $method->setAccessible(true);

        return $method->invoke($obj);
    }
}
