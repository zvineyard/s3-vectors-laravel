<?php

namespace Vineyard\S3Vectors\Support;

class VectorHasher
{
    public static function hash(array $vector, int $precision = 4): string
    {
        $rounded = array_map(fn($v) => round((float)$v, $precision), $vector);
        return md5(json_encode($rounded));
    }
}