<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use UnitEnum;
use DateInterval;
use Aws\Sqs\SqsClient;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Queue\SqsQueue;
use Illuminate\Contracts\Queue\Job;

/**
 * @phpstan-import-type DiskOptions from ResolvesPointers
 */
class SqsDiskQueue extends SqsQueue
{
    use ResolvesPointers;

    /**
     * The disk options to save large payloads.
     *
     * @var DiskOptions
     */
    protected array $diskOptions;

    /**
     * Create a new Amazon SQS queue instance.
     *
     * @param  string  $default
     * @param  DiskOptions  $diskOptions
     * @param  string  $prefix
     * @param  string  $suffix
     * @param  bool  $dispatchAfterCommit
     * @return void
     */
    public function __construct(
        SqsClient $sqs,
        $default,
        $diskOptions,
        $prefix = '',
        $suffix = '',
        $dispatchAfterCommit = false,
    ) {
        $this->diskOptions = $diskOptions;

        parent::__construct($sqs, $default, $prefix, $suffix, $dispatchAfterCommit);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload
     * @param  UnitEnum|string|null  $queue
     * @param  array<string, mixed>  $options
     * @param  DateTimeInterface|DateInterval|int  $delay
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [], $delay = 0)
    {
        $queueUrl = $this->getQueue($queue);

        $message = [
            'QueueUrl' => $queueUrl,
            'MessageBody' => $this->resolveMessageBody($payload, $queueUrl),
        ];

        if ($delay) {
            $message['DelaySeconds'] = $this->secondsUntil($delay);
        }

        return $this->sqs->sendMessage($message)->get('MessageId');
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  DateTimeInterface|DateInterval|int  $delay
     * @param  string  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue ?: $this->default, $data),
            $queue,
            $delay,
            function (string $payload, UnitEnum|string|null $queue) use ($delay) {
                return $this->pushRaw($payload, $queue, [], $delay);
            }
        );
    }

    /**
     * Build a single entry for the SendMessageBatch API.
     *
     * @param  int  $id
     * @param  array{job: mixed, delay: mixed, payload: string}  $message
     * @param  string|null  $queue
     * @return array<mixed>
     */
    protected function prepareSendMessageBatchEntry($id, array $message, $queue)
    {
        return [
            ...parent::prepareSendMessageBatchEntry($id, $message, $queue),
            'MessageBody' => $this->resolveMessageBody($message['payload'], $this->getQueue($queue)),
        ];
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string|null  $queue
     * @return Job|null
     */
    public function pop($queue = null)
    {
        /** @var list<array<string, mixed>>|null $messages */
        $messages = $this->sqs->receiveMessage([
            'QueueUrl' => $queue = $this->getQueue($queue),
            'MessageSystemAttributeNames' => ['ApproximateReceiveCount'],
        ])['Messages'];

        return empty($messages) ? null : new SqsDiskJob(
            $this->container,
            $this->sqs,
            $messages[0],
            $this->connectionName,
            $queue,
            $this->diskOptions
        );
    }

    /**
     * Delete all the jobs from the queue.
     *
     * @param  string  $queue
     * @return int
     */
    public function clear($queue = null)
    {
        return tap(parent::clear($queue), function (): void {
            if (Arr::get($this->diskOptions, 'cleanup') && $prefix = $this->diskOptions['prefix'] ?? null) {
                $this->resolveDisk()->deleteDirectory($prefix);
            }
        });
    }
}
