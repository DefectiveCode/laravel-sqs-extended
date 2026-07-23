<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Laravel\Vapor\Queue\VaporJob;
use Laravel\Vapor\Console\Commands\VaporWorkCommand as LaravelVaporWorkCommand;

class VaporWorkCommand extends LaravelVaporWorkCommand
{
    /**
     * Marshal the job with the given message ID.
     *
     *
     * @return VaporJob
     */
    protected function marshalJob(array $message)
    {
        $normalizedMessage = $this->normalizeMessage($message);

        $queue = $this->worker->getManager()->connection('sqs');

        return new VaporSqsDiskJob(
            $this->laravel,
            $queue->getSqs(),
            $normalizedMessage,
            'sqs',
            $this->queueUrl($message),
            config('queue.connections.sqs.disk_options')
        );
    }
}
