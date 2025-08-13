<?php

namespace Vineyard\S3Vectors;

use Aws\S3Vectors\S3VectorsClient;
use Illuminate\Support\Facades\Cache;
use Vineyard\S3Vectors\Contracts\S3VectorsRepositoryInterface;
use Vineyard\S3Vectors\Support\VectorHasher;

class S3VectorsRepository implements S3VectorsRepositoryInterface
{
    protected S3VectorsClient $client;
    protected string $bucket;
    protected string $index;
    protected int $hashPrecision;
    protected int $defaultTopK;
    protected int $ttlQuery;
    protected int $ttlGet;

    // Optional: simple per-request, in-memory cache to de-dupe
    protected array $localCache = [];

    public function __construct(S3VectorsClient $client, string $bucket, string $index, int $hashPrecision = 4, int $defaultTopK = 5, int $ttlQuery = 300, int $ttlGet = 600)
    {
        $this->client        = $client;
        $this->bucket        = $bucket;
        $this->index         = $index;
        $this->hashPrecision = $hashPrecision;
        $this->defaultTopK   = $defaultTopK;
        $this->ttlQuery      = $ttlQuery;
        $this->ttlGet        = $ttlGet;
    }

    /*** Public API ***/

    public function query(array $vector, int $topK = null, int $ttl = null): array
    {
        $topK = $topK ?? $this->defaultTopK;
        $ttl  = $ttl  ?? $this->ttlQuery;

        $hash = VectorHasher::hash($vector, $this->hashPrecision);
        $baseKey = sprintf('s3v:query:%s:%s:k=%d:%s', $this->bucket, $this->index, $topK, $hash);

        // Per-request memory cache
        if (isset($this->localCache[$baseKey])) {
            return $this->localCache[$baseKey];
        }

        $callback = function () use ($vector, $topK) {
            $res = $this->client->queryVectors([
                'vectorBucketName' => $this->bucket,
                'indexName'        => $this->index,
                'queryVector'      => ['float32' => array_map('floatval', $vector)],
                'topK'             => $topK,
                'returnDistance'   => true,
                'returnMetadata'   => true,
            ])->toArray();

            $out = [
                'vectors' => array_map(fn($v) => [
                    'key'      => $v['key'] ?? null,
                    'distance' => $v['distance'] ?? null,
                    'metadata' => $v['metadata'] ?? null,
                ], $res['vectors'] ?? []),
            ];

            return $out;
        };

        $data = $this->remember($baseKey, $ttl, $callback);
        return $this->localCache[$baseKey] = $data;
    }

    public function getVectors(array $keys, int $ttl = null, bool $returnData = false): array
    {
        $ttl = $ttl ?? $this->ttlGet;
        sort($keys);
        $baseKey = sprintf('s3v:get:%s:%s:%s:%s', $this->bucket, $this->index, $returnData ? 'data1' : 'data0', md5(json_encode($keys)));

        $callback = function () use ($keys, $returnData) {
            return $this->client->getVectors([
                'vectorBucketName' => $this->bucket,
                'indexName'        => $this->index,
                'keys'             => $keys,
                'returnMetadata'   => true,
                'returnData'       => $returnData,
            ])->toArray();
        };

        return $this->remember($baseKey, $ttl, $callback);
    }

    public function putVectors(array $vectors): void
    {
        // Normalize floats
        $payload = array_map(function ($v) {
            if (!isset($v['key']) || !isset($v['data']['float32'])) {
                throw new \InvalidArgumentException("Each vector requires 'key' and 'data.float32'.");
            }
            $v['data']['float32'] = array_map('floatval', $v['data']['float32']);
            return $v;
        }, $vectors);

        $this->client->putVectors([
            'vectorBucketName' => $this->bucket,
            'indexName'        => $this->index,
            'vectors'          => $payload,
        ]);

        $this->invalidateIndexCache();
    }

    public function deleteVectors(array $keys): void
    {
        $this->client->deleteVectors([
            'vectorBucketName' => $this->bucket,
            'indexName'        => $this->index,
            'keys'             => $keys,
        ]);

        $this->invalidateIndexCache();
    }

    public function putVectorsBatched(array $vectors, int $batchSize = 500): void
    {
        $batches = array_chunk($vectors, $batchSize);
        foreach ($batches as $i => $batch) {
            // Basic retry/backoff hook
            $attempts = 0; $max = 3; $delayMs = 250;
            start: try {
                $this->putVectors($batch);
            } catch (\Throwable $e) {
                if (++$attempts < $max) {
                    usleep($delayMs * 1000);
                    $delayMs *= 2; // exponential backoff
                    goto start;
                }
                throw $e;
            }
        }
    }

    public function deleteVectorsBatched(array $keys, int $batchSize = 500): void
    {
        $batches = array_chunk($keys, $batchSize);
        foreach ($batches as $i => $batch) {
            $attempts = 0; $max = 3; $delayMs = 250;
            start: try {
                $this->deleteVectors($batch);
            } catch (\Throwable $e) {
                if (++$attempts < $max) {
                    usleep($delayMs * 1000);
                    $delayMs *= 2;
                    goto start;
                }
                throw $e;
            }
        }
    }

    /*** Cache helpers (taggable vs non-taggable) ***/

    protected function remember(string $baseKey, int $ttl, \Closure $callback)
    {
        if ($this->supportsTags()) {
            return Cache::tags($this->tags())->remember($baseKey, $ttl, $callback);
        }
        $key = $this->withVersion($baseKey);
        return Cache::remember($key, $ttl, $callback);
    }

    protected function invalidateIndexCache(): void
    {
        if ($this->supportsTags()) {
            Cache::tags($this->tags())->flush();
            $this->localCache = [];
            return;
        }
        $this->bumpVersion();
        $this->localCache = [];
    }

    protected function tags(): array
    {
        return ["s3vectors:index:{$this->bucket}:{$this->index}"];
    }

    protected function supportsTags(): bool
    {
        try {
            Cache::tags(['_capability_probe_']);
            return true;
        } catch (\BadMethodCallException $e) {
            return false;
        }
    }

    protected function versionKey(): string
    {
        return "s3v:ver:{$this->bucket}:{$this->index}";
    }

    protected function currentVersion(): string
    {
        $key = $this->versionKey();
        $ver = Cache::get($key);
        if ($ver === null) {
            $ver = (string) random_int(1, PHP_INT_MAX);
            Cache::put($key, $ver, 60 * 60 * 24 * 365);
        }
        return (string) $ver;
    }

    protected function bumpVersion(): void
    {
        $key = $this->versionKey();
        try {
            $new = Cache::increment($key);
            if ($new === false || $new === null) {
                Cache::put($key, (string) random_int(1, PHP_INT_MAX), 60 * 60 * 24 * 365);
            }
        } catch (\Throwable $e) {
            Cache::put($key, (string) random_int(1, PHP_INT_MAX), 60 * 60 * 24 * 365);
        }
    }

    protected function withVersion(string $baseKey): string
    {
        return 'v' . $this->currentVersion() . ':' . $baseKey;
    }
}