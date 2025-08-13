<?php

namespace Vineyard\S3Vectors\Contracts;

interface S3VectorsRepositoryInterface
{
    /** Query the index with a vector */
    public function query(array $vector, int $topK = null, int $ttl = null): array;

    /** Get vectors by keys (metadata; set returnData = true if needed) */
    public function getVectors(array $keys, int $ttl = null, bool $returnData = false): array;

    /** Put (create/update) multiple vectors */
    public function putVectors(array $vectors): void;

    /** Delete multiple vectors by key */
    public function deleteVectors(array $keys): void;

    /** Convenience: chunk vectors into batches and put */
    public function putVectorsBatched(array $vectors, int $batchSize = 500): void;

    /** Convenience: chunk keys into batches and delete */
    public function deleteVectorsBatched(array $keys, int $batchSize = 500): void;
}