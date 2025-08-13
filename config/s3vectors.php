<?php

return [
    // AWS region that matches your Vector Bucket
    'region' => env('S3V_REGION', env('AWS_DEFAULT_REGION', 'us-east-2')),

    // Vector bucket and index names
    'bucket' => env('S3V_BUCKET', 'your-vector-bucket'),
    'index'  => env('S3V_INDEX',  'your-index-name'),

    // Cache TTLs (seconds)
    'ttl' => [
        'query' => env('S3V_TTL_QUERY', 300),  // 5 min
        'get'   => env('S3V_TTL_GET',   600),  // 10 min
    ],

    // Hash precision for query vectors to stabilize cache keys
    'hash_precision' => env('S3V_HASH_PRECISION', 4),

    // Optional: default topK if not provided
    'default_topk' => env('S3V_DEFAULT_TOPK', 5),
];