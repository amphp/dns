<?php

namespace Amp\Dns\Test;

use Amp\Dns\Cache;
use Amp\Dns\Cache\APCCache;
use Amp\Dns\Cache\MemoryCache;
use Amp\Dns\Cache\RedisCache;
use Predis\Client as RedisClient;
use Predis\Connection\ConnectionException as RedisConnectionException;

class CacheTest extends \PHPUnit_Framework_TestCase {
    private static $redisEnabled = true;

    private static $redisParameters = [
        'connection_timeout' => 2,
        'read_write_timeout' => 2,
    ];

    public static function setUpBeforeClass() {
        try {
            $predisClient = new RedisClient(self::$redisParameters, []);
            $predisClient->ping();
            //It's connected
        } catch (RedisConnectionException $rce) {
            self::$redisEnabled = false;
        }
    }

    /**
     * Create a mocked cache from the interface, and test that it works
     * according to it's spec.
     */
    public function testCacheWorks() {
        $mock = \Mockery::mock('Amp\Dns\Cache');

        $cacheValues = [];

        $cacheGetFunction = function($name, $type, callable $callback) use (&$cacheValues) {
            $key = $name . '_' . $type;

            if (array_key_exists($key, $cacheValues)) {
                $callback(true, $cacheValues[$key]);
            } else {
                $callback(false, null);
            }
        };

        $cacheStoreFunction = function($name, $type, $value, $ttl = null) use (&$cacheValues) {
            $cacheValues[$name . '_' . $type] = $value;
        };

        $cacheDeleteFunction = function($name, $type) use (&$cacheValues) {
            unset($cacheValues[$name . '_' . $type]);
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
    public function testAPCCache() {
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
    public function testRedisCache() {
        if (self::$redisEnabled == false) {
            $this->markTestSkipped("Could not connect to Redis, skipping test.");
            return;
        }

        $prefix = time().'_'.uniqid('CacheTest');
        try {
            $predisClient = new \Predis\Client(self::$redisParameters, []);
        } catch (RedisConnectionException $rce) {
            $this->markTestIncomplete("Could not connect to Redis server, cannot test redis cache.");
            return;
        }

        $redisCache = new RedisCache($predisClient, $prefix);
        $this->runCacheTest($redisCache);
    }

    public function testMemoryCache() {
        $memoryCache = new MemoryCache;
        $this->runCacheTest($memoryCache);
    }

    /**
     * Runs the actual test against an instance of a cache.
     * @param \Amp\Dns\Cache $cache
     */
    public function runCacheTest(Cache $cache) {
        $name = 'example.com';
        $type = 1;
        $ttl = 3600;
        $value1 = '12345';
        $value2 = '54321';

        $cache->get($name, $type, function($alreadyExisted, $retrievedValue) {
            $this->assertFalse($alreadyExisted);
            $this->assertNull($retrievedValue);
        });

        $cache->store($name, $type, $value1, $ttl);

        $cache->get($name, $type, function($alreadyExisted, $retrievedValue) use($value1) {
            $this->assertTrue($alreadyExisted);
            $this->assertEquals($value1, $retrievedValue);
        });

        $cache->delete($name, $type);

        $cache->get($name, $type, function($alreadyExisted, $retrievedValue) {
            $this->assertFalse($alreadyExisted);
            $this->assertNull($retrievedValue);
        });

        $cache->store($name, $type, $value2, $ttl);

        $cache->get($name, $type, function($alreadyExisted, $retrievedValue) use($value2) {
            $this->assertTrue($alreadyExisted);
            $this->assertEquals($value2, $retrievedValue);
        });
    }

    public function testMemoryCacheGarbageCollection() {
        $name = 'example.com';
        $type = 1;
        $ttl = 3600;
        $value = '12345';

        $memoryCache = new MemoryCache;

        //A TTL of zero should be expired instantly
        $memoryCache->store($name, $type, $value, 0);
        $memoryCache->get($name, $type, function($cacheHit) {
            $this->assertFalse($cacheHit);
        });

        //A negative TTL should be expired instantly
        $memoryCache->store($name, $type, $value, -5);
        $memoryCache->get($name, $type, function($cacheHit) {
            $this->assertFalse($cacheHit);
        });

        //A positive TTL should be cached
        $memoryCache->store($name, $type, $value, 5);
        $memoryCache->get($name, $type, function($alreadyExisted, $retrievedValue) use($value) {
            $this->assertTrue($alreadyExisted);
            $this->assertEquals($value, $retrievedValue);
        });

        //check that garbage collection collects.
        //todo
        $memoryCache->store($name, $type, $value, 0);
    }
}
