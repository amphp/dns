<?php

namespace Amp\Dns\Test;

use Amp\NativeReactor;
use Amp\Dns\Cache;
use Amp\Dns\Client;
use Amp\Dns\Resolver;
use Amp\Dns\Cache\APCCache;
use Amp\Dns\Cache\MemoryCache;
use Amp\Dns\Cache\RedisCache;
use Predis\Client as RedisClient;
use Predis\Connection\ConnectionException as RedisConnectionException;

class IntegrationTest extends \PHPUnit_Framework_TestCase {
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

    public function testWithNullCache() {
        $this->basicRun(null);
    }

    public function testWithMemoryCache() {
        $memoryCache = new MemoryCache();
        $this->basicRun($memoryCache);
    }

    /**
     * @requires extension APC
     */
    public function testWithApcCache() {
        $prefix = time().uniqid('CacheTest');
        $apcCache = new APCCache($prefix);
        $this->basicRun($apcCache);
    }

    public function testWithRedisCache() {
        if (self::$redisEnabled != true) {
            $this->markTestSkipped("Could not connect to Redis, skipping test.");
            return;
        }

        $prefix = time().'_'.uniqid('CacheTest');
        try {
            $redisClient = new RedisClient(self::$redisParameters, []);
        }
        catch (RedisConnectionException $rce) {
            $this->markTestIncomplete("Could not connect to Redis server, cannot test redis cache.");
            return;
        }

        $redisCache = new RedisCache($redisClient, $prefix);
        $this->basicRun($redisCache);
    }

    /**
     * @group internet
     */
    public function basicRun(Cache $cache = null) {
        $names = [
            'google.com',
            'github.com',
            'stackoverflow.com',
            'localhost',
            '192.168.0.1',
            '::1',
        ];

        $reactor = new NativeReactor;
        $client = new Client($reactor, null, null, $cache);
        $resolver = new Resolver($client);

        $promises = [];
        foreach ($names as $name) {
            $promises[$name] = $resolver->resolve($name);
        }

        $comboPromise = \Amp\all($promises);
        $results = \Amp\wait($comboPromise, $reactor);

        foreach ($results as $name => $addrStruct) {
            list($addr, $type) = $addrStruct;
            $validIP = @inet_pton($addr);
            $this->assertNotFalse(
                $validIP,
                "Server name $name did not resolve to a valid IP address"
            );
        }
    }

    /**
     * Check that caches do actually cache results.
     */
    function testCachingOfResults() {
        $memoryCache = new MemoryCache();

        $namesFirstRun = [
            'google.com',
            'github.com',
            'google.com',
            'github.com',
        ];

        $namesSecondRun = [
            'google.com',
            'github.com',
        ];

        $setCount = count(array_unique(array_merge($namesFirstRun, $namesSecondRun)));
        $getCount = count($namesFirstRun) + count($namesSecondRun);

        $mockedCache = \Mockery::mock($memoryCache);

        /** @var  $mockedCache \Mockery\Mock */

        $mockedCache->shouldReceive('store')->times($setCount)->passthru();
        $mockedCache->shouldReceive('get')->times($getCount)->passthru();

        $mockedCache->makePartial();

        $reactor = new NativeReactor;
        $client = new Client($reactor, null, null, $mockedCache);
        $resolver = new Resolver($client);

        $promises = [];
        foreach ($namesFirstRun as $name) {
            $promises[$name] = $resolver->resolve($name);
        }

        $comboPromise = \Amp\all($promises);
        $results = \Amp\wait($comboPromise, $reactor);

        foreach ($results as $name => $addrStruct) {
            list($addr, $type) = $addrStruct;
            $validIP = @inet_pton($addr);
            $this->assertNotFalse(
                $validIP,
                "Server name $name did not resolve to a valid IP address"
            );
        }

        $promises = [];
        foreach ($namesSecondRun as $name) {
            $promises[$name] = $resolver->resolve($name);
        }

        $comboPromise = \Amp\all($promises);
        $results = \Amp\wait($comboPromise, $reactor);
        foreach ($results as $name => $addrStruct) {
            list($addr, $type) = $addrStruct;
            $validIP = @inet_pton($addr);
            $this->assertNotFalse(
                $validIP,
                "Server name $name did not resolve to a valid IP address"
            );
        }
    }
}
