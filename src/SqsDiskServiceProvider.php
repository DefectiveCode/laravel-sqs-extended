<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Illuminate\Queue\QueueManager;
use Laravel\Vapor\Queue\VaporWorker;
use Illuminate\Support\ServiceProvider;

class SqsDiskServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function boot(): void
    {
        /** @var QueueManager $manager */
        $manager = $this->app->make('queue');
        $manager->addConnector('sqs-disk', fn () => new SqsDiskConnector);

        $this->app->extend('command.vapor.work', function () {
            /** @var VaporWorker $worker */
            $worker = $this->app->make('queue.vaporWorker');

            return new VaporWorkCommand($worker);
        });
    }
}
