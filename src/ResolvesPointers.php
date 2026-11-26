<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Illuminate\Support\Arr;
use UnexpectedValueException;
use Aws\Exception\AwsException;
use League\Flysystem\UnableToWriteFile;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * @phpstan-type DiskOptions array{disk?: string|null, prefix?: string|null, always_store?: bool, cleanup?: bool, max_size?: int|string|null}
 */
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
     *
     * @var array<string, int>
     */
    protected array $queueMaxLengths = [];

    protected function resolveMessageBody(string $payload, ?string $queueUrl = null): string
    {
        if (strlen($payload) < $this->maxSqsLength($queueUrl) && ! Arr::get($this->diskOptions, 'always_store')) {
            return $payload;
        }

        $decodedPayload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $uuid = data_get($decodedPayload, 'uuid');

        if (! is_string($uuid)) {
            throw new UnexpectedValueException('The queue payload must contain a string uuid to be stored on a disk.');
        }

        $filepath = ($this->diskOptions['prefix'] ?? '')."/{$uuid}.json";

        if ($this->resolveDisk()->put($filepath, $payload) === false) {
            throw UnableToWriteFile::atLocation($filepath);
        }

        return json_encode([
            'pointer' => $filepath,
            'job' => data_get($decodedPayload, 'job'),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Resolves the payload length at which a message must become a pointer.
     */
    protected function maxSqsLength(?string $queueUrl): int
    {
        if ($configured = $this->diskOptions['max_size'] ?? null) {
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
            $result = $this->sqs->getQueueAttributes([
                'QueueUrl' => $queueUrl,
                'AttributeNames' => ['MaximumMessageSize'],
            ]);
        } catch (AwsException) {
            return self::MAX_SQS_LENGTH;
        }

        $maximum = (int) filter_var(data_get($result, 'Attributes.MaximumMessageSize'), FILTER_VALIDATE_INT);

        return $maximum > self::MESSAGE_SIZE_MARGIN
            ? $maximum - self::MESSAGE_SIZE_MARGIN
            : self::MAX_SQS_LENGTH;
    }

    /**
     * Resolves the configured queue disk that stores large payloads.
     */
    protected function resolveDisk(): Filesystem
    {
        return $this->container->make(Factory::class)->disk($this->diskOptions['disk'] ?? null);
    }
}
