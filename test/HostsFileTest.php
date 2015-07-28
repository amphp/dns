<?php

namespace Amp\Dns\Test;

use Amp\Dns\HostsFile;
use Amp\Dns\NameValidator;
use Amp\Dns\AddressModes as Mode;

/**
 * Class HostsFileTest
 * @group hosts
 */
class HostsFileTest extends \PHPUnit_Framework_TestCase {

    public function testBasicIPV4() {
        $tests = [
            ['192.168.1.1', 'host1.example.com', Mode::INET4_ADDR, Mode::INET4_ADDR],
            ['192.168.1.2', 'host2.example.com', Mode::INET4_ADDR, Mode::INET4_ADDR],

            //Check that ipv4 is returned when both v4 and v6 are is set
            ['192.168.1.1', 'host1.example.com', Mode::INET4_ADDR | Mode::INET6_ADDR, Mode::INET4_ADDR],
            ['192.168.1.2', 'host2.example.com', Mode::INET4_ADDR | Mode::INET6_ADDR, Mode::INET4_ADDR],

            //Check that ipv4 is returned when ANY_* is set
            ['192.168.1.1', 'host1.example.com', Mode::ANY_PREFER_INET4, Mode::INET4_ADDR],
            ['192.168.1.2', 'host2.example.com', Mode::ANY_PREFER_INET4, Mode::INET4_ADDR],
            ['192.168.1.1', 'host1.example.com', Mode::ANY_PREFER_INET6, Mode::INET4_ADDR],
            ['192.168.1.2', 'host2.example.com', Mode::ANY_PREFER_INET6, Mode::INET4_ADDR],

            //Check request for ipv6 returns null
            [null, 'host1.example.com', Mode::INET6_ADDR, null],

            //Check non-existant domains return null
            [null, 'host4.example.com', Mode::INET4_ADDR, null],
        ];

        $this->runHostsFileTests($tests, __DIR__ . '/fixtures/ipv4Hosts.txt');
    }

    public function testBasicIPV6() {
        $tests = [
            //Examples taken from http://en.wikipedia.org/wiki/IPv6_address
            ['2001:db8::2:1', 'host1.example.com', Mode::INET6_ADDR, Mode::INET6_ADDR],
            ['2001:db8:0:1:1:1:1:1', 'host2.example.com', Mode::INET6_ADDR, Mode::INET6_ADDR],
            ['2001:db8::1:0:0:1', 'host3.example.com', Mode::INET6_ADDR, Mode::INET6_ADDR],

            //Check that ipv6 is returned when both v4 and v6 are is set
            ['2001:db8::2:1', 'host1.example.com', Mode::INET6_ADDR | Mode::INET4_ADDR, Mode::INET6_ADDR],
            ['2001:db8:0:1:1:1:1:1', 'host2.example.com', Mode::INET6_ADDR | Mode::INET4_ADDR, Mode::INET6_ADDR],
            ['2001:db8::1:0:0:1', 'host3.example.com', Mode::INET6_ADDR | Mode::INET4_ADDR, Mode::INET6_ADDR],

            //Check that ipv6 is returned when ANY_* is set
            ['2001:db8::2:1', 'host1.example.com', Mode::ANY_PREFER_INET4, Mode::INET6_ADDR],
            ['2001:db8:0:1:1:1:1:1', 'host2.example.com', Mode::ANY_PREFER_INET4, Mode::INET6_ADDR],
            ['2001:db8::1:0:0:1', 'host3.example.com', Mode::ANY_PREFER_INET4, Mode::INET6_ADDR],
            ['2001:db8::2:1', 'host1.example.com', Mode::ANY_PREFER_INET6, Mode::INET6_ADDR],
            ['2001:db8:0:1:1:1:1:1', 'host2.example.com', Mode::ANY_PREFER_INET6, Mode::INET6_ADDR],
            ['2001:db8::1:0:0:1', 'host3.example.com', Mode::ANY_PREFER_INET6, Mode::INET6_ADDR],

            //Check request for ipv4 returns null
            [null, 'host1.example.com', Mode::INET4_ADDR, null],

            //Check non-existant domains return null
            [null, 'host4.example.com', Mode::INET6_ADDR, null],
        ];

        $this->runHostsFileTests($tests, __DIR__ . '/fixtures/ipv6Hosts.txt');
    }

    public function testMixedIPVersions() {
        $tests = [
            //Examples taken from http://en.wikipedia.org/wiki/IPv6_address
            ['2001:db8::2:1', 'host1.example.com', Mode::INET6_ADDR, Mode::INET6_ADDR],
            ['2001:db8:0:1:1:1:1:1', 'host2.example.com', Mode::INET6_ADDR, Mode::INET6_ADDR],
            ['2001:db8::1:0:0:1', 'host3.example.com', Mode::INET6_ADDR, Mode::INET6_ADDR],
            ['192.168.1.1', 'host1.example.com', Mode::INET4_ADDR, Mode::INET4_ADDR],
            ['192.168.1.2', 'host2.example.com', Mode::INET4_ADDR, Mode::INET4_ADDR],
            ['192.168.1.4', 'host4.example.com', Mode::INET4_ADDR, Mode::INET4_ADDR],

            //Check that v4 is returned by default
            ['192.168.1.1', 'host1.example.com', Mode::INET4_ADDR | Mode::INET6_ADDR, Mode::INET4_ADDR],

            //Check that the prefer inet6 works
            ['2001:db8::2:1', 'host1.example.com', Mode::INET4_ADDR | Mode::INET6_ADDR | Mode::PREFER_INET6, Mode::INET6_ADDR],

            //Check that the ANY_* works
            ['192.168.1.1', 'host1.example.com', Mode::ANY_PREFER_INET4, Mode::INET4_ADDR],
            ['2001:db8::2:1', 'host1.example.com', Mode::ANY_PREFER_INET6, Mode::INET6_ADDR],

            //Check that a host that is only listed as ipv4 does not return a result for ipv6
            [null, 'host4.example.com', Mode::INET6_ADDR, null],

            //Check that a host that is only listed as ipv6 does not return a result for ipv4
            [null, 'host3.example.com', Mode::INET4_ADDR, null],
        ];

        $this->runHostsFileTests($tests, __DIR__ . '/fixtures/mixedVersionHosts.txt');
    }

    public function runHostsFileTests($tests, $hostsFile) {
        $nameValidator = new NameValidator;
        $hostsFile = new HostsFile($nameValidator, $hostsFile);

        foreach ($tests as $i => $test) {
            list($expectedResult, $hostname, $inputAddrMode, $expectedAddrMode) = $test;

            $result = $hostsFile->resolve($hostname, $inputAddrMode);

            if ($expectedResult === null) {
                $this->assertNull($result);
            } else {
                list($resolvedAddr, $resolvedMode) = $result;

                $this->assertEquals(
                    $expectedAddrMode,
                    $resolvedMode,
                    "Failed to resolve $hostname to $expectedResult. " .
                    "Expected `" . var_export($expectedAddrMode, true) . "` " .
                    "but got `" . var_export($resolvedAddr, true) . "` " .
                    " when running test #" . $i
                );

                $this->assertEquals(
                    $expectedResult,
                    $resolvedAddr,
                    "Failed to resolve $hostname to $expectedResult. " .
                    "Expected `$expectedResult` but got `$resolvedAddr` " .
                    " when running test #" . $i
                );
            }
        }
    }
}
