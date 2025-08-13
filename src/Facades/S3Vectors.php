<?php

namespace Vineyard\S3Vectors\Facades;

use Illuminate\Support\Facades\Facade;

class S3Vectors extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 's3vectors.repo';
    }
}