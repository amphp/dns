<?php

use Addr\HostsFile;
use Addr\NameValidator;
use Addr\AddressModes;

/**
 * Class HostsFileTest
 * @group hosts
 */
class HostsFileTest extends \PHPUnit_Framework_TestCase {

    function testBasicIPV4() {
        $tests = [
            ['192.168.1.1', 'host1.example.com', AddressModes::INET4_ADDR, AddressModes::INET4_ADDR],
            ['192.168.1.1', 'host2.example.com', AddressModes::INET4_ADDR, AddressModes::INET4_ADDR],

            //Check that ipv4 is returned when prefer_inet6 is set
            ['192.168.1.1', 'host1.example.com', AddressModes::PREFER_INET6, AddressModes::INET4_ADDR],

            //Check non-existant domains return null
            [null, 'host4.example.com', AddressModes::INET4_ADDR, null],
        ];
        
        $this->runHostsFileTests($tests, __DIR__.'/../fixtures/ipv4Hosts.txt');
    }


    function testBasicIPV6() {
        $tests = [
            //Examples taken from http://en.wikipedia.org/wiki/IPv6_address
            ['2001:db8::2:1', 'host1.example.com', AddressModes::INET6_ADDR, AddressModes::INET6_ADDR],
            ['2001:db8:0:1:1:1:1:1', 'host2.example.com', AddressModes::INET6_ADDR, AddressModes::INET6_ADDR],
            ['2001:db8::1:0:0:1', 'host3.example.com', AddressModes::INET6_ADDR, AddressModes::INET6_ADDR],

            //Check that prefer inet6 does indeed return an inet6 address
            ['2001:db8::2:1', 'host1.example.com', AddressModes::PREFER_INET6, AddressModes::INET6_ADDR],
            
            //Check request for ipv4 returns null
            [null, 'host1.example.com', AddressModes::INET4_ADDR, null],
        ];

        $this->runHostsFileTests($tests, __DIR__.'/../fixtures/ipv6Hosts.txt');
    }

    function testMixedIPVersions() {
        $tests = [
            //Examples taken from http://en.wikipedia.org/wiki/IPv6_address
            ['2001:db8::2:1', 'host1.example.com', AddressModes::INET6_ADDR, AddressModes::INET6_ADDR],
            ['2001:db8:0:1:1:1:1:1', 'host2.example.com', AddressModes::INET6_ADDR, AddressModes::INET6_ADDR],
            ['2001:db8::1:0:0:1', 'host3.example.com', AddressModes::INET6_ADDR, AddressModes::INET6_ADDR],
            ['192.168.1.1', 'host1.example.com', AddressModes::INET4_ADDR, AddressModes::INET4_ADDR],
            ['192.168.1.1', 'host2.example.com', AddressModes::INET4_ADDR, AddressModes::INET4_ADDR],
            
            //Check that the prefer inet6 works
            ['2001:db8::2:1', 'host1.example.com', AddressModes::PREFER_INET6, AddressModes::INET6_ADDR],
            
            //Check that a host that is only listed as ipv6 does not return a result for ipv4
            [null, 'host1.example.com', AddressModes::INET4_ADDR, null],
            
        ];

        $this->runHostsFileTests($tests, __DIR__.'/../fixtures/mixedVersionHosts.txt');
    }
    
    
    
    function runHostsFileTests($tests, $hostsFile) {
        $nameValidator = new NameValidator;
        $hostsFile = new HostsFile($nameValidator, $hostsFile);

        foreach ($tests as $test) {
            list($expectedResult, $hostname, $inputAddrMode, $expectedAddrMode) = $test;

            $result = $hostsFile->resolve($hostname, $inputAddrMode);

            if ($expectedResult === null) {
                $this->assertNull($result);
            }
            else {
                list($resolvedAddr, $resolvedMode) = $result;
                    $this->assertEquals(
                        $expectedAddrMode,
                        $resolvedMode,
                        "Incorrect address type received was expecting $expectedAddrMode but received $resolvedMode."
                    );
                
                $this->assertEquals(
                    $expectedResult,
                    $resolvedAddr,
                    "Failed to resolve $hostname from $expectedResult. Expected `$expectedResult` but got `$resolvedAddr`."
                );
            }
        }
    }
}
