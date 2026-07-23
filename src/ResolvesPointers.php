<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Illuminate\Support\Arr;
use League\Flysystem\UnableToWriteFile;
use Illuminate\Filesystem\FilesystemAdapter;

trait ResolvesPointers
{
    /**
     * The max length of a SQS message before it must be stored as a pointer.
     *
     * @var int
     */
    public const MAX_SQS_LENGTH = 250000;

    protected function resolveMessageBody(string $payload): string
    {
        if (strlen($payload) < self::MAX_SQS_LENGTH && ! Arr::get($this->diskOptions, 'always_store')) {
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
