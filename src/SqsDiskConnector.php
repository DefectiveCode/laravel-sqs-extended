<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Aws\Sqs\SqsClient;
use Illuminate\Support\Arr;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\SqsConnector;
use Illuminate\Queue\Connectors\ConnectorInterface;

/**
 * @phpstan-import-type DiskOptions from ResolvesPointers
 */
class SqsDiskConnector extends SqsConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     *
     * @param  array{queue: string, disk_options: DiskOptions, key?: string|null, secret?: string|null, token?: string|null, prefix?: string, suffix?: string, after_commit?: bool|null}  $config
     * @return Queue
     */
    public function connect(array $config)
    {
        /** @var array{queue: string, disk_options: DiskOptions, key?: string|null, secret?: string|null, token?: string|null, prefix?: string, suffix?: string, after_commit?: bool|null} $config */
        $config = $this->getDefaultConfiguration($config);

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $config['credentials'] = Arr::only($config, ['key', 'secret', 'token']);
        }

        return new SqsDiskQueue(
            new SqsClient(
                Arr::except($config, ['token'])
            ),
            $config['queue'],
            $config['disk_options'],
            $config['prefix'] ?? '',
            $config['suffix'] ?? '',
            $config['after_commit'] ?? false,
        );
    }
}
