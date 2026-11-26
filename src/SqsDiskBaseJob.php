<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Aws\Sqs\SqsClient;
use Illuminate\Support\Arr;
use Illuminate\Container\Container;

/**
 * @phpstan-import-type DiskOptions from ResolvesPointers
 */
trait SqsDiskBaseJob
{
    use ResolvesPointers;

    /**
     * The Amazon SQS client instance.
     *
     * @var SqsClient
     */
    protected $sqs;

    /**
     * Holds the raw body to prevent fetching the file from
     * the disk multiple times.
     */
    protected $cachedRawBody = null;

    /**
     * The disk options for the job.
     *
     * @var DiskOptions
     */
    protected array $diskOptions;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $job
     * @param  string  $connectionName
     * @param  string  $queue
     * @param  DiskOptions  $diskOptions
     * @return void
     */
    public function __construct(Container $container, SqsClient $sqs, array $job, $connectionName, $queue, array $diskOptions)
    {
        $this->sqs = $sqs;
        $this->job = $job;
        $this->queue = $queue;
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->diskOptions = $diskOptions;
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        if (Arr::get($this->diskOptions, 'cleanup') && $pointer = $this->resolvePointer()) {
            $this->resolveDisk()->delete($pointer);
        }
    }

    /**
     * Get the raw body string for the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        if ($this->cachedRawBody) {
            return $this->cachedRawBody;
        }

        if ($pointer = $this->resolvePointer()) {
            /** @var string $body */
            $body = $this->resolveDisk()->get($pointer);

            return $this->cachedRawBody = $body;
        }

        return parent::getRawBody();
    }

    /**
     * Resolves the job payload pointer.
     */
    protected function resolvePointer(): ?string
    {
        $body = $this->job['Body'] ?? null;
        if (! is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body);
        if (! is_object($decoded) || ! property_exists($decoded, 'pointer')) {
            return null;
        }

        $pointer = $decoded->pointer;

        return is_string($pointer)
            ? $pointer
            : (is_scalar($pointer) ? (string) $pointer : null);
    }
}
