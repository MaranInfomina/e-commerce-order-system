<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Milestone 3's async order flow. QUEUE_CONNECTION stays `rabbitmq`
        // outside tests; phpunit.xml forces it to `sync` for the whole suite
        // so most tests run jobs synchronously in-process, and the one test
        // that needs the real broker (tests/Feature/QueueConnectivityTest.php)
        // resolves this connection explicitly instead of relying on the
        // default.
        //
        // ADAPTED from the plan's original block: the plan's shape (a
        // top-level 'connection' => AMQPLazyConnection::class, and a nested
        // 'options.exchange' array plus 'options.queue.declare/passive/
        // durable/exclusive/auto_delete') matches an OLDER major of
        // vladimir-yuldashev/laravel-queue-rabbitmq (~v11-v13). The version
        // that actually resolves against Laravel 13.17/PHP 8.4 is v15.0.2,
        // whose config schema changed:
        //   - No 'connection' key needed. v15's
        //     Queue\Connection\ConfigFactory builds an AMQPConnectionConfig
        //     that defaults to a LAZY connection already (config key
        //     'lazy', defaulting to true) via the modern
        //     AMQPConnectionFactory::create() path; the AMQPLazyConnection
        //     class-name form is a now-deprecated fallback the connector
        //     still accepts but the README no longer recommends.
        //   - 'options.exchange' as a nested array is not read at all by
        //     v15 (Queue\QueueConfigFactory only reads a FLAT
        //     'options.queue.exchange' / 'exchange_type' /
        //     'exchange_routing_key' for the "publish through a named
        //     exchange" feature, which this task doesn't need — the
        //     default exchange is fine for a single `orders` queue).
        //   - 'options.queue.declare/passive/durable/exclusive/auto_delete'
        //     are not read by v15 either: Queue\RabbitMQQueue::declareQueue()
        //     always declares durable=true, non-exclusive queues itself, so
        //     there is no config knob for it any more (and nothing to set:
        //     durable is already what this task wants).
        // Verified against vendor/vladimir-yuldashev/laravel-queue-rabbitmq's
        // README.md and its Queue/QueueConfigFactory.php +
        // Queue/Connection/ConfigFactory.php source after `composer require`.
        'rabbitmq' => [
            'driver' => 'rabbitmq',
            'queue' => env('RABBITMQ_QUEUE', 'orders'),

            'hosts' => [
                [
                    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
                    'port' => env('RABBITMQ_PORT', 5672),
                    'user' => env('RABBITMQ_USER', 'guest'),
                    'password' => env('RABBITMQ_PASSWORD', 'guest'),
                    'vhost' => env('RABBITMQ_VHOST', '/'),
                ],
            ],

            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
