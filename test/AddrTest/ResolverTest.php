<?php




namespace AddrTest;

use Addr\AddressModes;
use Addr\Cache;
use Alert\ReactorFactory;
use Addr\Client;
use Addr\NameValidator;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\MessageFactory;
use LibDNS\Records\QuestionFactory;
use Addr\RequestBuilder;
use Addr\ResponseInterpreter;
use Addr\HostsFile;
use Addr\Resolver;
use Addr\ResolutionErrors;

/**
 * 
 * 
 * @group resolver 
 */
class ResolverTest extends \PHPUnit_Framework_TestCase {


    /**
     * @return Resolver
     */
    function createResolver($reactor, $createClient = true)
    {
        $nameValidator = new NameValidator;
        $cache = new Cache\MemoryCache;
        

        $serverAddr = null;
        $serverPort = null;
        $requestTimeout = null;
        $hostsFilePath = null;
        
        $client = null;
        
        if ($createClient) {
            $client = new Client(
                $reactor,
                new RequestBuilder(
                    new MessageFactory,
                    new QuestionFactory,
                    (new EncoderFactory)->create()
                ),
                new ResponseInterpreter(
                    (new DecoderFactory)->create()
                ),
                $cache, $serverAddr, $serverPort, $requestTimeout
            );
        }

        $hostsFile = new HostsFile($nameValidator, __DIR__.'/../fixtures/resolverTest.txt');
        $resolver = new Resolver($reactor, $nameValidator, $client, $hostsFile);

        return $resolver;
    }
    
    function testInvalidName()
    {
        $reactor = (new ReactorFactory)->select();
        $resolver = $this->createResolver($reactor);

        $callbackCount = 0;
        $resultAddr1 = null;
        $resultType1 = null;
        
        $callback1 = function ($addr, $type) use (&$callbackCount, &$resultAddr1, &$resultType1) {
            $callbackCount++;
            $resultAddr1 = $addr;
            $resultType1 = $type;
        };
        
        $alphabet = "abcdefghijklmnopqrstuvwxyz";
        $tooLongName = $alphabet.$alphabet; //52
        $tooLongName = $tooLongName.$tooLongName; //104
        $tooLongName = $tooLongName.$tooLongName; //208
        $tooLongName = $tooLongName.$alphabet;    //234
        $tooLongName = $tooLongName.$alphabet;    //260

        $resolver->resolve($tooLongName, $callback1, AddressModes::PREFER_INET6);
        
        $reactor->run();

        $this->assertEquals(1, $callbackCount);
        $this->assertNull($resultAddr1);
        $this->assertSame(ResolutionErrors::ERR_INVALID_NAME, $resultType1);
    }


    /**
     * @group internet
     */
    function testUnknownName()
    {
        $reactor = (new ReactorFactory)->select();
        $resolver = $this->createResolver($reactor);

        $callbackCount = 0;
        $resultAddr1 = null;
        $resultType1 = null;

        $callback1 = function ($addr, $type) use (&$callbackCount, &$resultAddr1, &$resultType1) {
            $callbackCount++;
            $resultAddr1 = $addr;
            $resultType1 = $type;
        };

        $resolver->resolve("doesntexist", $callback1, AddressModes::PREFER_INET6);
        $reactor->run();

        $this->assertEquals(1, $callbackCount);
        $this->assertNull($resultAddr1);
        $this->assertSame(ResolutionErrors::ERR_NO_RECORD, $resultType1);
    }
    

    /**
     * Check that getting localhost name resolves correctly.
     */
    function testLocalHost()
    {
        $reactor = (new ReactorFactory)->select();
        $resolver = $this->createResolver($reactor);

        $callbackCount = 0;
        $resultAddr1 = null;
        $resultType1 = null;

        $resultAddr2 = null;
        $resultType2 = null;

        $callback1 = function ($addr, $type) use (&$callbackCount, &$resultAddr1, &$resultType1) {
            $callbackCount++;
            $resultAddr1 = $addr;
            $resultType1 = $type;
        };

        $callback2 = function ($addr, $type) use (&$callbackCount, &$resultAddr2, &$resultType2) {
            $callbackCount++;
            $resultAddr2 = $addr;
            $resultType2 = $type;
        };

        $resolver->resolve("localhost", $callback1, AddressModes::INET4_ADDR);
        $resolver->resolve("localhost", $callback2, AddressModes::PREFER_INET6);

        $reactor->run();

        $this->assertEquals(2, $callbackCount);
        $this->assertSame('127.0.0.1', $resultAddr1);
        $this->assertSame(AddressModes::INET4_ADDR, $resultType1, "Wrong result type - should be INET4_ADDR but got $resultType1");

        $this->assertSame('::1', $resultAddr2);
        $this->assertSame(AddressModes::INET6_ADDR, $resultType2, "Wrong result type - should be INET6_ADDR but got $resultType2");
    }


    /**
     * Basic tests
     */
    function testBasic()
    {
        $reactor = (new ReactorFactory)->select();
        $resolver = $this->createResolver($reactor);

        $callbackCount = 0;
        $resultAddr1 = null;
        $resultType1 = null;

        $resultAddr2 = null;
        $resultType2 = null;

        $callback1 = function ($addr, $type) use (&$callbackCount, &$resultAddr1, &$resultType1) {
            $callbackCount++;
            $resultAddr1 = $addr;
            $resultType1 = $type;
        };

        $callback2 = function ($addr, $type) use (&$callbackCount, &$resultAddr2, &$resultType2) {
            $callbackCount++;
            $resultAddr2 = $addr;
            $resultType2 = $type;
        };

        $resolver->resolve("host1.example.com", $callback1, AddressModes::INET4_ADDR);
        $resolver->resolve("resolvertest", $callback2, AddressModes::INET4_ADDR);
        $reactor->run();
        
        $this->assertEquals(2, $callbackCount);
        $this->assertSame('192.168.1.1', $resultAddr1);
        $this->assertSame(AddressModes::INET4_ADDR, $resultType1);

        $this->assertSame('192.168.1.3', $resultAddr2);
        $this->assertSame(AddressModes::INET4_ADDR, $resultType1);
    }


    function testWithoutClient()
    {
        $reactor = (new ReactorFactory)->select();

        $resolver = $this->createResolver($reactor, false);

        $names = ["host1.example.com", 'news.bbc.co.uk'];
        $results = [];

        foreach ($names as $name) {
            $resolver->resolve($name, function($addr) use($name, $resolver, &$results) {
                    $results[] = [$name, $addr];
                });
        }

        $reactor->run();
        
        $this->assertCount(
            count($names),
            $results,
            "At least one of the name lookups did not call the callback."
        );
    }
}