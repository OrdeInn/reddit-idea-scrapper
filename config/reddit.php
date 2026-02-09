<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reddit OAuth Credentials
    |--------------------------------------------------------------------------
    |
    | Credentials for Reddit's OAuth2 password grant flow. Create a "script"
    | type application at https://www.reddit.com/prefs/apps
    |
    */

    'client_id' => env('REDDIT_CLIENT_ID', ''),
    'client_secret' => env('REDDIT_CLIENT_SECRET', ''),
    'username' => env('REDDIT_USERNAME', ''),
    'password' => env('REDDIT_PASSWORD', ''),
    'user_agent' => env('REDDIT_USER_AGENT', 'SaaSScanner/1.0'),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Reddit allows 60 requests per minute for OAuth clients. We use conservative
    | defaults to avoid hitting limits.
    |
    */

    'rate_limit' => [
        'requests_per_minute' => 60,
        'delay_between_requests_ms' => 1000,
        'max_retries' => 3,
        'retry_delay_ms' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fetch Settings
    |--------------------------------------------------------------------------
    |
    | Parameters for fetching posts and comments from subreddits.
    |
    */

    'fetch' => [
        // First scan looks back this many months
        'default_timeframe_months' => 1,
        'default_timeframe_weeks' => 1,

        // Rescan looks back this many weeks for new content
        'rescan_timeframe_weeks' => 2,

        // Minimum engagement thresholds to fetch a post
        'min_upvotes' => 5,
        'min_comments' => 3,

        // Reddit API returns max 100 items per request
        'posts_per_request' => 100,

        // How deep to fetch comment replies (0 = top-level only, 1 = one reply deep)
        'comment_depth' => 1,

        // Maximum comments to fetch per post
        'max_comments_per_post' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | API Endpoints
    |--------------------------------------------------------------------------
    |
    | Reddit API base URLs.
    |
    */

    'endpoints' => [
        'oauth_token' => 'https://www.reddit.com/api/v1/access_token',
        'api_base' => 'https://oauth.reddit.com',
    ],
];
