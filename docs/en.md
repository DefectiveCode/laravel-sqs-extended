# Laravel SQS Extended

## Introduction

Laravel SQS Extended is a Laravel queue driver that was designed to work around the AWS SQS payload size limits. This queue driver will automatically serialize large payloads to a disk (typically S3) and then unserialize them at run time. This package took inspiration from https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/sqs-s3-messages.html.

## Migration from Simple SQS Extended Client

1. Remove `simplesoftwareio/simple-sqs-extended-client` package from your project.
2. Install `defectivecode/laravel-sqs-extended` package.

The old configuration is backwards compatible with the new package. The only change is the package name.

## Install

1. First create a bucket that will hold all of your large SQS payloads.

> We highly recommend you use a _private_ bucket when storing SQS payloads. Payloads can contain sensitive information and should never be shared publicly.

2. Run `composer require defectivecode/laravel-sqs-extended` to install the queue driver.

3. Then, add the following default queue settings to your `queue.php` file.

> Laravel Vapor users must set the connection name set to `sqs`. The `sqs` connection is looked for within Vapor Core and this library will not work as expected if you use a different connection name.

```
  /*
  |--------------------------------------------------------------------------
  | SQS Disk Queue Configuration
  |--------------------------------------------------------------------------
  |
  | Here you may configure the SQS disk queue driver.  It shares all of the same
  | configuration options from the built in Laravel SQS queue driver.  The only added
  | option is `disk_options` which are explained below.
  |
  | always_store: Determines if all payloads should be stored on a disk regardless of the queue's size limit.
  | cleanup:      Determines if the payload files should be removed from the disk once the job is processed. Leaveing the
  |                 files behind can be useful to replay the queue jobs later for debugging reasons.
  | disk:         The disk to save SQS payloads to.  This disk should be configured in your Laravel filesystems.php config file.
  | max_size:     Optional. Pins the offload threshold to a fixed number of bytes. When omitted, the threshold is read from
  |                 the queue itself. See "Payload size threshold" below.
  | prefix        The prefix (folder) to store the payloads with.  This is useful if you are sharing a disk with other SQS queues.
  |                 Using a prefix allows for the queue:clear command to destroy the files separately from other sqs-disk backed queues
  |                 sharing the same disk.
  |
  */
  'sqs' => [
      'driver' => 'sqs-disk',
      'key' => env('AWS_ACCESS_KEY_ID'),
      'secret' => env('AWS_SECRET_ACCESS_KEY'),
      'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
      'queue' => env('SQS_QUEUE', 'default'),
      'suffix' => env('SQS_SUFFIX'),
      'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
      'after_commit' => false,
      'disk_options' => [
          'always_store' => false,
          'cleanup' => false,
          'disk' => env('SQS_DISK'),
          'prefix' => 'bucket-prefix',
      ],
  ],
```

4. Boot up your queues and profit without having to worry about SQS's payload limit 🥳

## Payload size threshold

AWS raised the maximum SQS payload from 256 KiB to 1 MiB in August 2025, but `MaximumMessageSize` is a per-queue attribute — queues created before that change keep the older 262144 byte value until you raise it. Rather than assume either limit, this package reads the attribute from the queue and offloads only what genuinely will not fit.

The threshold is resolved in the following order, and the first match wins. A payload is stored on the disk when its length is greater than or equal to the resolved threshold.

| Condition                                       | Threshold               | Reads the queue attribute |
| ----------------------------------------------- | ----------------------- | ------------------------- |
| `always_store` is `true`                        | Every payload is stored | No                        |
| `max_size` is set                               | The configured value    | No                        |
| Queue reports `262144` (the pre-2025 default)   | 250000                  | Yes                       |
| Queue reports `1048576` (raised to the maximum) | 1036432                 | Yes                       |
| Attribute cannot be read                        | 250000                  | Attempted once            |

The reserved headroom of 12144 bytes covers per-message overhead, and is sized so a queue left at the 262144 byte default resolves to the 250000 byte threshold used by earlier versions of this package. Upgrading does not change behavior until you raise the queue's own limit.

### Permissions

Reading the attribute requires `sqs:GetQueueAttributes`. The result is cached in memory per queue, so this costs one call per queue for the lifetime of the process rather than one call per job.

If the attribute cannot be read — a narrow IAM policy, or an SQS emulator that does not implement it — the driver falls back to the 250000 byte threshold and continues dispatching. Set `max_size` to skip the lookup entirely if you would rather not grant the permission.

### Raising a queue's limit

```
aws sqs set-queue-attributes \
    --queue-url https://sqs.us-east-1.amazonaws.com/your-account-id/your-queue \
    --attributes MaximumMessageSize=1048576
```

Note that SQS meters usage in 64 KB chunks, so a single 1 MiB message bills as 16 requests. Storing large payloads on a disk is often still the cheaper path.
