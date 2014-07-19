<?php

namespace AddrCache;


class APCCache implements \AddrCache\Cache {

    /**
     * @param string $prefix A prefix to prepend to all keys.
     */
    function __construct($prefix = 'AddrCache\Cache\APCCache') {
        $this->prefix = $prefix;
    }

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
    public function get($key) {
        $key = $this->prefix.$key;
        $value = apc_fetch($key, $success);
        
        if ($success) {
            return [true, $value];
        }
        return [false, null];
    }

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param $key
     * @param $value
     * @param null $ttl
     */
    public function store($key, $value, $ttl = null) {
        $key = $this->prefix.$key;
        apc_store($key, $value, $ttl);
    }

    /**
     * Deletes an entry from the cache.
     * @param $key
     */
    public function delete($key) {
        $key = $this->prefix.$key;
        apc_delete($key);
    }
}