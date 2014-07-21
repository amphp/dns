<?php

namespace Addr\Cache;

use Addr\Cache;

class APCCache implements Cache
{
    /**
     * @param string $prefix A prefix to prepend to all keys.
     */
    public function __construct($prefix = 'AddrCache\Cache\APCCache')
    {
        $this->prefix = $prefix;
    }

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
    public function get($name)
    {
        $name = $this->prefix . $name;
        $value = apc_fetch($name, $success);

        if ($success) {
            return [true, $value];
        }

        return [false, null];
    }

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param $name
     * @param $value
     * @param null $ttl
     */
    public function store($name, $value, $ttl = null)
    {
        $name = $this->prefix . $name;
        apc_store($name, $value, $ttl);
    }

    /**
     * Deletes an entry from the cache.
     * @param $name
     */
    public function delete($name)
    {
        $name = $this->prefix . $name;
        apc_delete($name);
    }
}
