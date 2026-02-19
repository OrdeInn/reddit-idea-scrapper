<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If the
    | domain is empty, Horizon will reside under the same domain as the
    | application. Remember to configure your DNS entries accordingly.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failures, job metrics, and other information.
    |
    */

    'use' => env('HORIZON_USE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    | LLM queues (classify, extract) get higher thresholds since jobs are
    | naturally slower due to external API calls.
    |
    */

    'waits' => [
        'redis:default'  => 60,
        'redis:fetch'    => 60,
        'redis:classify' => 120,
        'redis:extract'  => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silenced jobs will not show up in the Horizon dashboard. This is useful
    | for noisy polling jobs that clutter the dashboard. Add job class names
    | here if needed.
    |
    */

    'silenced' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to represent a
    | temporary aggregation of a job or queue's metrics. By default, it's
    | set at 24 to represent the last 24 hours. Adjust as needed.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait for all workers to terminate unless the job timeout value is
    | explicitly zero. Otherwise, all open jobs will be completed first.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 128,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs. The "defaults" array contains the default configuration
    | that is applied to all workers unless overridden.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue'      => ['default'],
            'balance'    => 'auto',
            'maxTime'    => 0,
            'maxJobs'    => 0,
            'memory'     => 128,
            'tries'      => 1,
            'timeout'    => 60,
            'nice'       => 0,
        ],
    ],

    'environments' => [

        'local' => [

            /*
             |------------------------------------------------------------------
             | Default Supervisor — StartScanJob
             |------------------------------------------------------------------
             | Sequential scan orchestration. Light DB operations only.
             | Fixed to 1 worker — scans are initiated one at a time.
             */
            'default-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['default'],
                'balance'    => false,
                'processes'  => 1,
                'maxTime'    => 0,
                'maxJobs'    => 0,
                'memory'     => 128,
                'tries'      => 1,
                'timeout'    => 30,
                'nice'       => 0,
            ],

            /*
             |------------------------------------------------------------------
             | Fetch Supervisor — FetchPostsJob, FetchCommentsJob, CheckFetchCompleteJob
             |------------------------------------------------------------------
             | Reddit API calls + polling checks. Network I/O heavy.
             | Auto-balanced between 1-3 workers to handle parallel fetching.
             */
            'fetch-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['fetch'],
                'balance'      => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'maxTime'      => 0,
                'maxJobs'      => 0,
                'memory'       => 128,
                'tries'        => 3,
                'timeout'      => 120,
                'nice'         => 0,
            ],

            /*
             |------------------------------------------------------------------
             | Classify Supervisor — ClassifyPostsJob, ClassifyPostJob, CheckClassificationCompleteJob
             |------------------------------------------------------------------
             | Dual LLM calls (Anthropic + OpenAI via Concurrency::run()).
             | Fixed workers to prevent bursty API traffic and rate limit spikes.
             | 2 workers × 2 providers = 4 max concurrent LLM API calls.
             */
            'classify-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['classify'],
                'balance'    => false,
                'processes'  => 2,
                'maxTime'    => 0,
                'maxJobs'    => 0,
                'memory'     => 128,
                'tries'      => 3,
                'timeout'    => 300,
                'nice'       => 0,
            ],

            /*
             |------------------------------------------------------------------
             | Extract Supervisor — ExtractIdeasJob, ExtractPostIdeasJob, CheckExtractionCompleteJob
             |------------------------------------------------------------------
             | Single LLM call (Anthropic Sonnet) per job.
             | Fixed workers to prevent bursty API traffic.
             | 2 workers = 2 max concurrent LLM API calls.
             */
            'extract-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['extract'],
                'balance'    => false,
                'processes'  => 2,
                'maxTime'    => 0,
                'maxJobs'    => 0,
                'memory'     => 128,
                'tries'      => 3,
                'timeout'    => 300,
                'nice'       => 0,
            ],

        ],

        'production' => [

            /*
             |------------------------------------------------------------------
             | Default Supervisor (production)
             |------------------------------------------------------------------
             */
            'default-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['default'],
                'balance'    => false,
                'processes'  => 1,
                'maxTime'    => 0,
                'maxJobs'    => 0,
                'memory'     => 128,
                'tries'      => 1,
                'timeout'    => 30,
                'nice'       => 0,
            ],

            /*
             |------------------------------------------------------------------
             | Fetch Supervisor (production) — higher worker ceiling
             |------------------------------------------------------------------
             */
            'fetch-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['fetch'],
                'balance'      => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'maxTime'      => 0,
                'maxJobs'      => 0,
                'memory'       => 128,
                'tries'        => 3,
                'timeout'      => 120,
                'nice'         => 0,
            ],

            /*
             |------------------------------------------------------------------
             | Classify Supervisor (production) — 3 workers = 6 concurrent LLM calls
             |------------------------------------------------------------------
             */
            'classify-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['classify'],
                'balance'    => false,
                'processes'  => 3,
                'maxTime'    => 0,
                'maxJobs'    => 0,
                'memory'     => 128,
                'tries'      => 3,
                'timeout'    => 300,
                'nice'       => 0,
            ],

            /*
             |------------------------------------------------------------------
             | Extract Supervisor (production) — 3 workers = 3 concurrent LLM calls
             |------------------------------------------------------------------
             */
            'extract-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['extract'],
                'balance'    => false,
                'processes'  => 3,
                'maxTime'    => 0,
                'maxJobs'    => 0,
                'memory'     => 128,
                'tries'      => 3,
                'timeout'    => 300,
                'nice'       => 0,
            ],

        ],

    ],

];
