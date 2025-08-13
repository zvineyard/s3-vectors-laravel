<?php

namespace Vineyard\S3Vectors;

use Aws\S3Vectors\S3VectorsClient;
use Illuminate\Support\ServiceProvider;
use Vineyard\S3Vectors\Contracts\S3VectorsRepositoryInterface;

class S3VectorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(__DIR__ . '/../config/s3vectors.php', 's3vectors');

        // Bind AWS client
        $this->app->singleton(S3VectorsClient::class, function ($app) {
            $config = $app['config']->get('s3vectors');
            return new S3VectorsClient([
                'version'     => 'latest',
                'region'      => $config['region'],
                'credentials' => [
                    'key'    => env('AWS_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY'),
                ],
            ]);
        });

        // Bind repository
        $this->app->singleton('s3vectors.repo', function ($app) {
            $cfg = $app['config']->get('s3vectors');
            return new S3VectorsRepository(
                $app->make(S3VectorsClient::class),
                $cfg['bucket'],
                $cfg['index'],
                $cfg['hash_precision'] ?? 4,
                $cfg['default_topk'] ?? 5,
                $cfg['ttl']['query'] ?? 300,
                $cfg['ttl']['get'] ?? 600,
            );
        });

        // Contract binding for type-hinting
        $this->app->bind(S3VectorsRepositoryInterface::class, fn($app) => $app->make('s3vectors.repo'));
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/s3vectors.php' => config_path('s3vectors.php'),
        ], 'config');
    }
}