<?php

namespace Amp\Test\Dns;

use Amp\NativeReactor;
use Amp\Dns\AddressModes;
use Amp\Dns\Client;
use Amp\Dns\HostsFile;
use Amp\Dns\Resolver;
use Amp\Dns\ResolutionErrors;

class ResolverTest extends \PHPUnit_Framework_TestCase {

    private function createResolver($hostsFile = null) {
        $reactor = new NativeReactor;
        $hostsFile = new HostsFile(null, $hostsFile);
        $client = new Client($reactor);
        $resolver = new Resolver($client, $hostsFile);

        return [$reactor, $resolver];
    }

    /**
     * @expectedException Amp\Dns\ResolutionException
     * @expectedErrorCode Amp\Dns\ResolutionErrors::ERR_INVALID_NAME
     */
    public function testInvalidName() {
        list($reactor, $resolver) = $this->createResolver();

        $alphabet = implode(range('a', 'z'));
        $tooLongName = $alphabet.$alphabet; //52
        $tooLongName = $tooLongName.$tooLongName; //104
        $tooLongName = $tooLongName.$tooLongName; //208
        $tooLongName = $tooLongName.$alphabet;    //234
        $tooLongName = $tooLongName.$alphabet;    //260

        $resolver->resolve($tooLongName, AddressModes::PREFER_INET6)->wait();
    }

    /**
     * @group internet
     * @expectedException Amp\Dns\ResolutionException
     * @expectedErrorCode Amp\Dns\ResolutionErrors::ERR_NO_RECORD
     */
    public function testUnknownName() {
        list($reactor, $resolver) = $this->createResolver();
        $resolver->resolve("doesntexist", AddressModes::PREFER_INET6)->wait();
    }

    public function testLocalHostResolution() {
        list($reactor, $resolver) = $this->createResolver();

        list($addr, $type) = $resolver->resolve("localhost", AddressModes::INET4_ADDR)->wait();
        $this->assertSame('127.0.0.1', $addr);
        $this->assertSame(AddressModes::INET4_ADDR, $type, "Wrong result type - should be INET4_ADDR but got $type");

        list($addr, $type) = $resolver->resolve("localhost", AddressModes::PREFER_INET6)->wait();
        $this->assertSame('::1', $addr);
        $this->assertSame(AddressModes::INET6_ADDR, $type, "Wrong result type - should be INET6_ADDR but got $type");
    }

    public function testHostsFileResolution() {
        $hostsFile = __DIR__ . '/fixtures/resolverTest.txt';
        list($reactor, $resolver) = $this->createResolver($hostsFile);

        list($addr, $type) = $resolver->resolve("host1.example.com", AddressModes::INET4_ADDR)->wait();
        $this->assertSame('192.168.1.1', $addr);
        $this->assertSame(AddressModes::INET4_ADDR, $type);

        list($addr, $type) = $resolver->resolve("resolvertest", AddressModes::INET4_ADDR)->wait();
        $this->assertSame('192.168.1.3', $addr);
        $this->assertSame(AddressModes::INET4_ADDR, $type);
    }
}
