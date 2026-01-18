<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Illuminate\Support\Arr;
use Illuminate\Filesystem\FilesystemAdapter;

trait ResolvesPointers
{
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
