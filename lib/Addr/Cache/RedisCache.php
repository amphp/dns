<?php

namespace Addr\Cache;

use Addr\Cache,
    Predis\Client as RedisClient;

class RedisCache implements Cache
{
    /**
     * @var RedisClient
     */
    private $redisClient;

    /**
     * Lua script to get a value, and flag whether it was cache hit or miss.
     * Don't delete the "== 1" - it's important in Lua
     *
     * @var string
     */
    private $fetchLuaScript = <<< SCRIPT
if redis.call("exists", KEYS[1]) == 1
then
    return {1, redis.call("get",KEYS[1])}
else
    return {0, 0}
end
SCRIPT;

    /**
     * Constructor
     *
     * @param RedisClient $redisClient
     * @param string $prefixKey A prefix to prepend to all keys. This can also be 
     * set via the redis client, in which case you may wish to pass an empty string
     * as $prefixKey
     */
    public function __construct(RedisClient $redisClient, $prefixKey = 'Addr\Cache\RedisCache')
    {
        $this->redisClient = $redisClient;
        $this->prefix = $prefixKey;
    }

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param string $name
     * @param string $value
     * @param int $ttl
     */
    public function store($name, $value, $ttl = null)
    {
        $name = $this->prefix . $name;
        $ttl = intval($ttl);
        if ($ttl > 0) {
            $this->redisClient->set($name, $value, 'EX', $ttl);
        }
        else {
            $this->redisClient->set($name, $value);
        }
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
        list($wasHit, $value) = $this->redisClient->eval($this->fetchLuaScript, 1, $name);
        if ($wasHit) {
            return [true, $value];
        }

        return [false, null];
    }

    /**
     * @param $name
     */
    public function delete($name)
    {
        $name = $this->prefix . $name;
        $this->redisClient->del([$name]);
    }
}
