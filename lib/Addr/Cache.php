<?php

namespace Addr;

interface Cache
{
    /**
     * Attempt to retrieve a value from the cache
     * 
     * Returns an array [$cacheHit, $value]
     * [true, $valueFromCache] - if it existed in the cache
     * [false, null] - if it didn't already exist in the cache
     * 
     * @param $name
     * @return array
     */
    public function get($name);

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param $name
     * @param $value
     * @param null $ttl
     */
    public function store($name, $value, $ttl = null);

    /**
     * Deletes an entry from the cache.
     *
     * @param $name
     */
    public function delete($name);
}
