<?php

namespace AddrTest;

use Addr\Cache,
    Addr\Cache\APCCache,
    Addr\Cache\MemoryCache,
    Addr\Cache\RedisCache,
    Predis\Client as RedisClient,
    Predis\Connection\ConnectionException as RedisConnectionException;

class CacheTest extends \PHPUnit_Framework_TestCase
{
    private static $redisEnabled = true;

    private static $redisParameters = [
        'connection_timeout' => 2,
        'read_write_timeout' => 2,
    ];

    public static function setUpBeforeClass()
    {
        try {
            $predisClient = new RedisClient(self::$redisParameters, []);
            $predisClient->ping();
            //It's connected
        }
        catch (RedisConnectionException $rce) {
            self::$redisEnabled = false;
        }
    }

    /**
     * Create a mocked cache from the interface, and test that it works
     * according to it's spec.
     */
    public function testCacheWorks()
    {
        $mock = \Mockery::mock('Addr\Cache');

        $cacheValues = [];

        $cacheGetFunction = function($key) use (&$cacheValues) {
            if (array_key_exists($key, $cacheValues)) {
                return [true, $cacheValues[$key]];
            }
            return [false, null];
        };

        $cacheStoreFunction = function($key, $value, $ttl = null) use (&$cacheValues) {
            $cacheValues[$key] = $value;
        };

        $cacheDeleteFunction = function($key) use (&$cacheValues) {
            unset($cacheValues[$key]);
        };

        $mock->shouldReceive('get')->withAnyArgs()->andReturnUsing($cacheGetFunction);
        $mock->shouldReceive('store')->withAnyArgs()->andReturnUsing($cacheStoreFunction);
        $mock->shouldReceive('delete')->withAnyArgs()->andReturnUsing($cacheDeleteFunction);

        $this->runCacheTest($mock);
    }

    /**
     * Test that the APC cache works as expected. Skipped if APC is not available.
     * 
     * @requires extension APC
     */
    public function testAPCCache()
    {
        $result = @apc_cache_info();

        if ($result === false) {
            $this->markTestSkipped("APC does not appear to be functioning, skipping test testAPCCache.");
            return;
        }

        $prefix = time().uniqid('CacheTest');
        $apcCache = new APCCache($prefix);
        $this->runCacheTest($apcCache);
    }

    /**
     * Test the redis cache works as expected.
     */
    public function testRedisCache()
    {
        if (self::$redisEnabled == false) {
            $this->markTestSkipped("Could not connect to Redis, skipping test.");
            return;
        }

        $prefix = time().'_'.uniqid('CacheTest');
        try {
            $predisClient = new \Predis\Client(self::$redisParameters, []);
        }
        catch (RedisConnectionException $rce) {
            $this->markTestIncomplete("Could not connect to Redis server, cannot test redis cache.");
            return;
        }

        $redisCache = new RedisCache($predisClient, $prefix);
        $this->runCacheTest($redisCache);
    }

    public function testMemoryCache()
    {
        $memoryCache = new MemoryCache;
        $this->runCacheTest($memoryCache);
    }

    /**
     * Runs the actual test against an instance of a cache.
     * @param \Addr\Cache $cache
     */
    public function runCacheTest(Cache $cache)
    {
        $key = 'TestKey';
        $value = '12345';
        $secondValue = '54321';

        list($alreadyExisted, $retrievedValue) = $cache->get($key);
        $this->assertFalse($alreadyExisted);
        $this->assertNull($retrievedValue);

        $cache->store($key, $value);

        list($alreadyExisted, $retrievedValue) = $cache->get($key);
        $this->assertTrue($alreadyExisted);
        $this->assertEquals($value, $retrievedValue);

        $cache->delete($key);

        list($alreadyExisted, $retrievedValue) = $cache->get($key);
        $this->assertFalse($alreadyExisted);
        $this->assertNull($retrievedValue);

        $cache->store($key, $secondValue);

        list($alreadyExisted, $retrievedValue) = $cache->get($key);
        $this->assertTrue($alreadyExisted);
        $this->assertEquals($secondValue, $retrievedValue);
    }   

    public function testMemoryCacheGarbageCollection()
    {
        $key = "TestKey";
        $value = '12345';

        $memoryCache = new MemoryCache;

        //A TTL of zero should be expired instantly
        $memoryCache->store($key, $value, 0);
        list($cacheHit, $cachedValue) = $memoryCache->get($key);
        $this->assertFalse($cacheHit);


        //A negative TTL oshould be expired instantly
        $memoryCache->store($key, $value, -5);
        list($cacheHit, $cachedValue) = $memoryCache->get($key);
        $this->assertFalse($cacheHit);


        //A positive TTL should be cached
        $memoryCache->store($key, $value, 5);
        list($cacheHit, $cachedValue) = $memoryCache->get($key);
        $this->assertTrue($cacheHit);
        $this->assertEquals($value, $cachedValue);


        //check that garbage collection collects.
        $memoryCache->store($key, $value, 0);
        $memoryCache->collectGarbage();

        //TODO - check that the memoryCache contains a single item
    }
}
