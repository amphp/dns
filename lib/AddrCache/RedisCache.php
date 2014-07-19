<?php


namespace AddrCache;

use Predis\Client as RedisClient;

class RedisCache implements \AddrCache\Cache {

    /**
     * @var RedisClient
     */
    private $redisClient;

    /**
     * Lua script to get a value, and flag whether it was cache hit or miss.
     * Don't delete the "== 1" - it's important in Lua 
     */
    const getLuaScript = <<< END
if redis.call("exists", KEYS[1]) == 1
then
    return {1, redis.call("get",KEYS[1])}
else
    return {0, 0}
end
    
END;

    /**
     * @param RedisClient $redisClient
     * @param string $prefixKey A prefix to prepend to all keys. This can also be 
     * set via the redis client, in which case you may wish to pass an empty string
     * as $prefixKey
     */
    function __construct(RedisClient $redisClient, $prefixKey = 'AddrCache\Cache\RedisCache') {
        $this->redisClient = $redisClient;
        $this->prefix = $prefixKey;
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
        $ttl = intval($ttl);
        if ($ttl > 0) {
            $this->redisClient->set($key, $value, 'EX', $ttl);
        }
        else {
            $this->redisClient->set($key, $value);
        }
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
        list($wasHit, $value) = $this->redisClient->eval(self::getLuaScript, 1, $key);
        if ($wasHit) {
            return [true, $value];
        }

        return [false, null];
    }

    
    /**
     * @param $key
     */
    public function delete($key) {
        $key = $this->prefix.$key;
        $this->redisClient->del([$key]);
    }
}

 