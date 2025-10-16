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
        $s = $this->makeSubject(['Body' => '']);
        $this->assertNull($this->callResolvePointer($s));
    }

    public function test_it_returns_null_when_body_is_invalid_json(): void
    {
        $s = $this->makeSubject(['Body' => '{']);
        $this->assertNull($this->callResolvePointer($s));
    }

    public function test_it_returns_null_when_pointer_is_missing(): void
    {
        $s = $this->makeSubject(['Body' => json_encode((object) ['foo' => 'bar'])]);
        $this->assertNull($this->callResolvePointer($s));
    }

    public function test_it_returns_pointer_string_when_present(): void
    {
        $s = $this->makeSubject(['Body' => json_encode((object) ['pointer' => 'manuals/a.pdf'])]);
        $this->assertSame('manuals/a.pdf', $this->callResolvePointer($s));
    }

    public function test_it_casts_numeric_pointer_to_string(): void
    {
        $s = $this->makeSubject(['Body' => json_encode((object) ['pointer' => 12345])]);
        $this->assertSame('12345', $this->callResolvePointer($s));
    }

    public function test_it_resolves_the_configured_disk_adapter(): void
    {
        $s = $this->makeSubject(['Body' => '{}'], ['disk' => 'archive']);

        $manager = Mockery::mock(FilesystemManager::class);
        $adapter = Mockery::mock(FilesystemAdapter::class);

        $manager->shouldReceive('disk')
            ->once()
            ->with('archive')
            ->andReturn($adapter);

        $s->container->instance('filesystem', $manager);

        $this->assertSame($adapter, $this->callResolveDisk($s));
    }

    private function callResolvePointer(object $obj): ?string
    {
        $m = new ReflectionMethod($obj, 'resolvePointer');
        $m->setAccessible(true);
        return $m->invoke($obj);
    }

    private function callResolveDisk(object $obj): FilesystemAdapter
    {
        $m = new ReflectionMethod($obj, 'resolveDisk');
        $m->setAccessible(true);
        return $m->invoke($obj);
    }
}
