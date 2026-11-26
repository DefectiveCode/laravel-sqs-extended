<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Laravel\Vapor\Queue\VaporJob;
use Laravel\Vapor\Queue\JobAttempts;
use Illuminate\Contracts\Queue\Job as JobContract;

class VaporSqsDiskJob extends VaporJob implements JobContract
{
    use SqsDiskBaseJob;

    public function release($delay = 0): void
    {
        $this->released = true;

        $payload = $this->payload();

        $payload['attempts'] = $this->attempts();

        $body = $this->resolveMessageBody(json_encode($payload, JSON_THROW_ON_ERROR), $this->queue);

        /** @var string $receiptHandle */
        $receiptHandle = $this->job['ReceiptHandle'];

        $this->sqs->deleteMessage([
            'QueueUrl' => $this->queue,
            'ReceiptHandle' => $receiptHandle,
        ]);

        /** @var string $jobId */
        $jobId = $this->sqs->sendMessage([
            'QueueUrl' => $this->queue,
            'MessageBody' => $body,
            'DelaySeconds' => $this->secondsUntil($delay),
        ])->get('MessageId');

        $this->container
            ->make(JobAttempts::class)
            ->transfer($this, $jobId);
    }
}
