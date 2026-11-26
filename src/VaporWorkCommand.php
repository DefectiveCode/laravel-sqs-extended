<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Illuminate\Queue\SqsQueue;
use Laravel\Vapor\Queue\VaporJob;
use Illuminate\Container\Container;
use Laravel\Vapor\Console\Commands\VaporWorkCommand as LaravelVaporWorkCommand;

/**
 * @phpstan-import-type DiskOptions from ResolvesPointers
 */
class VaporWorkCommand extends LaravelVaporWorkCommand
{
    /**
     * Marshal the job with the given message ID.
     *
     * @param  array<string, mixed>  $message
     * @return VaporJob
     */
    protected function marshalJob(array $message)
    {
        /** @var array<string, mixed> $normalizedMessage */
        $normalizedMessage = $this->normalizeMessage($message);

        /** @var SqsQueue $queue */
        $queue = $this->worker->getManager()->connection('sqs');

        /** @var Container $container */
        $container = $this->laravel;

        /** @var DiskOptions $diskOptions */
        $diskOptions = config()->array('queue.connections.sqs.disk_options');

        return new VaporSqsDiskJob(
            $container,
            $queue->getSqs(),
            $normalizedMessage,
            'sqs',
            $this->queueUrl($message),
            $diskOptions
        );
    }
}
