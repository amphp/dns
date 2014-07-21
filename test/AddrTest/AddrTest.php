<?php

namespace AddrTest;

use Addr\ResolverFactory,
    Alert\ReactorFactory;

use Addr\Cache;
use Addr\Cache\APCCache;
use Addr\Cache\MemoryCache;
use Addr\Cache\RedisCache;
use Predis\Client as RedisClient;
use Predis\Connection\ConnectionException as RedisConnectionException;


class AddrTest extends \PHPUnit_Framework_TestCase {


    static private $redisEnabled = true;

    static private $redisParameters = array(
        'connection_timeout' => 2,
        'read_write_timeout' => 2,
    );

    public static function setUpBeforeClass() {
        try {
            $predisClient = new RedisClient(self::$redisParameters, []);
            $predisClient->ping();
            //It's connected
        }
        catch (RedisConnectionException $rce) {
            self::$redisEnabled = false;
        }
    }

    function testWithNullCache() {
        $this->basicRun(null);
    }

    function testWithMemoryCache() {
        $memoryCache = new MemoryCache();
        $this->basicRun($memoryCache);
    }
    
    /**
     * @requires extension APC
     */
    function testWithApcCache() {
        $prefix = time().uniqid('CacheTest');
        $apcCache = new APCCache($prefix);
        $this->basicRun($apcCache);
    }


    function testWithRedisCache() {
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
    function basicRun(\Addr\Cache $cache = null) {
        $names = [
            'google.com',
            'github.com',
            'stackoverflow.com',
            'localhost',
            '192.168.0.1',
            '::1',
        ];

        $reactor = (new ReactorFactory)->select();
        $resolver = (new ResolverFactory)->createResolver(
            $reactor,
            null, //        $serverAddr = null,
            null, //$serverPort = null,
            null, //$requestTimeout = null,
            $cache,
            null //$hostsFilePath = null
        );
        
        $results = [];

        foreach ($names as $name) {
            $resolver->resolve($name, function($addr) use($name, $resolver, &$results) {
                    $results[$name] = $addr;
                });
        }

        $reactor->run();

        foreach ($results as $name => $addr) {
            $validIP = filter_var($addr, FILTER_VALIDATE_IP);
            $this->assertNotFalse(
                $validIP,
                "Server name $name did not resolve to a valid IP address"
            );
        }

        $this->assertCount(
            count($names),
            $results,
            "At least one of the name lookups did not resolve."
        );
        
    }
}




 