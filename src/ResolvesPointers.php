<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Illuminate\Support\Arr;
use Aws\Exception\AwsException;
use League\Flysystem\UnableToWriteFile;
use Illuminate\Filesystem\FilesystemAdapter;

trait ResolvesPointers
{
    /**
     * The fallback max length of a SQS message before it must be stored as a pointer.
     *
     * @var int
     */
    public const MAX_SQS_LENGTH = 250000;

    /**
     * Headroom reserved below the queue's MaximumMessageSize for per-message overhead.
     *
     * Sized so a queue left at the 262144 byte default resolves to MAX_SQS_LENGTH.
     *
     * @var int
     */
    public const MESSAGE_SIZE_MARGIN = 12144;

    /**
     * Cache of resolved thresholds, keyed by queue URL.
     */
    protected array $queueMaxLengths = [];

    protected function resolveMessageBody(string $payload, ?string $queueUrl = null): string
    {
        if (strlen($payload) < $this->maxSqsLength($queueUrl) && ! Arr::get($this->diskOptions, 'always_store')) {
            return $payload;
        }

        $decodedPayload = json_decode($payload);
        $filepath = Arr::get($this->diskOptions, 'prefix', '')."/{$decodedPayload->uuid}.json";

        if ($this->resolveDisk()->put($filepath, $payload) === false) {
            throw UnableToWriteFile::atLocation($filepath);
        }

        return json_encode([
            'pointer' => $filepath,
            'job' => $decodedPayload->job ?? null,
        ]);
    }

    /**
     * Resolves the payload length at which a message must become a pointer.
     */
    protected function maxSqsLength(?string $queueUrl): int
    {
        if ($configured = Arr::get($this->diskOptions, 'max_size')) {
            return (int) $configured;
        }

        if ($queueUrl === null) {
            return self::MAX_SQS_LENGTH;
        }

        return $this->queueMaxLengths[$queueUrl] ??= $this->detectMaxSqsLength($queueUrl);
    }

    /**
     * Reads the queue's MaximumMessageSize, less the reserved headroom.
     *
     * Falls back to MAX_SQS_LENGTH when the attribute is unreadable, so that
     * neither a narrow IAM policy nor an SQS emulator can stall dispatch.
     */
    protected function detectMaxSqsLength(string $queueUrl): int
    {
        try {
            $maximum = (int) $this->sqs->getQueueAttributes([
                'QueueUrl' => $queueUrl,
                'AttributeNames' => ['MaximumMessageSize'],
            ])['Attributes']['MaximumMessageSize'];
        } catch (AwsException) {
            return self::MAX_SQS_LENGTH;
        }

        return $maximum > self::MESSAGE_SIZE_MARGIN
            ? $maximum - self::MESSAGE_SIZE_MARGIN
            : self::MAX_SQS_LENGTH;
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

    /**
     * Resolves the configured queue disk that stores large payloads.
     */
    protected function resolveDisk(): FilesystemAdapter
    {
        return $this->container->make('filesystem')->disk(Arr::get($this->diskOptions, 'disk'));
    }
}
