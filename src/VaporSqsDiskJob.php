<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Laravel\Vapor\Queue\VaporJob;
use Illuminate\Contracts\Queue\Job as JobContract;

class VaporSqsDiskJob extends VaporJob implements JobContract
{
    use SqsDiskBaseJob;
}
