# vineyard/s3-vectors-laravel

Laravel integration for **AWS S3 Vectors**: query/get/put/delete with smart caching (Redis tags or versioned keys) and batch helpers.

## Install

```bash
composer require vineyard/s3-vectors-laravel

php artisan vendor:publish --tag=config --provider="Vineyard\\S3Vectors\\S3VectorsServiceProvider
```
### Set .env

```
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-2

S3V_REGION=us-east-2
S3V_BUCKET=your-vectors
S3V_INDEX=your-vectors-index
```

### Optional

```
CACHE_STORE=redis
S3V_TTL_QUERY=300
S3V_TTL_GET=600
S3V_HASH_PRECISION=4
S3V_DEFAULT_TOPK=5
```

## Usage

```php
use Vineyard\S3Vectors\Facades\S3Vectors;

// Query (cached)
$hits = S3Vectors::query($vector, topK: 5); // returns ['vectors' => [['key' => '...', 'distance' => 0.12, 'metadata' => [...]], ...]]

// Get metadata by keys (cached)
$info = S3Vectors::getVectors(['vectors/key1', 'vectors/key2']);

// Put many vectors (invalidates cache for this index)
S3Vectors::putVectors([
    ['key' => 'vectors/key1', 'data' => ['float32' => $v1], 'metadata' => ['doc_id' => '123']],
    ['key' => 'vectors/key2', 'data' => ['float32' => $v2]],
]);

// Batched put (chunks to 500 by default)
S3Vectors::putVectorsBatched($bigArrayOfVectors, 500);

// Delete
S3Vectors::deleteVectors(['vectors/key1', 'vectors/key2']);

// Batched delete (chunks to 500 by default)
S3Vectors::deleteVectorsBatched($bigArrayOfVectors, 500);
```

### Caching details

If the cache store supports tags (e.g., Redis), the package uses Cache::tags(["s3vectors:index:{bucket}:{index}"]) and flush() after writes.

Otherwise, it prefixes keys with a persistent version (e.g., v12345:) and bumps that version on writes to invalidate.

### Notes

Your vector bucket and index must already exist in AWS S3 Vectors, and the IAM principal must have s3vectors:QueryVectors, s3vectors:GetVectors, s3vectors:PutVectors, s3vectors:DeleteVectors as needed.

Datatype (float32 vs float64) and vector dimension must match the index configuration.

For heavy loads, prefer putVectorsBatched() and add retry/backoff.

### License

MIT