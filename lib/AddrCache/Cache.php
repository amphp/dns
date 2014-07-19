<?php


namespace AddrCache;

interface Cache {
    
    /**
     * Attempt to retrieve a value from the cache
     * 
     * Returns an array [$cacheHit, $value]
     * [true, $valueFromCache] - if it existed in the cache
     * [false, null] - if it didn't already exist in the cache
     * 
     * @param $key
     * @return array
     */
    public function get($key);

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param $key
     * @param $value
     * @param null $ttl
     */
    public function store($key, $value, $ttl = null);
    
    /**
     * Deletes an entry from the cache.
     * @param $key
     */
    public function delete($key);
}
