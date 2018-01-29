<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use Amp\Dns\Record;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use DaveRandom\Network\IPAddress;
use DaveRandom\Network\IPv4Address;
use DaveRandom\Network\IPv6Address;

class IntegrationTest extends TestCase {
    /**
     * @param string $hostname
     * @group internet
     * @dataProvider provideHostnames
     */
    public function testResolve($hostname) {
        Loop::run(function () use ($hostname) {
            $result = yield Dns\resolve($hostname);

            $this->assertInstanceOf(
                IPAddress::class, $result[0]->getValue()->getAddress(),
                "Server name $hostname did not resolve to a valid IP address"
            );
        });
    }

    /**
     * @group internet
     */
    public function testWorksAfterConfigReload() {
        Loop::run(function () {
            yield Dns\query("google.com", Record::A);
            $this->assertNull(yield Dns\resolver()->reloadConfig());
            $this->assertInternalType("array", yield Dns\query("example.com", Record::A));
        });
    }

    public function testResolveIPv4only() {
        Loop::run(function () {
            $records = yield Dns\resolve("google.com", Record::A);

            /** @var Record $record */
            foreach ($records as $record) {
                $this->assertSame(Record::A, $record->getType());
                $this->assertInstanceOf(
                    IPv4Address::class, $record->getValue()->getAddress(),
                    "Server name google.com did not resolve to a valid IPv4 address"
                );
            }
        });
    }

    public function testResolveIPv6only() {
        Loop::run(function () {
            $records = yield Dns\resolve("google.com", Record::AAAA);

            /** @var Record $record */
            foreach ($records as $record) {
                $this->assertSame(Record::AAAA, $record->getType());
                $this->assertInstanceOf(
                    IPv6Address::class, $record->getValue()->getAddress(),
                    "Server name google.com did not resolve to a valid IPv6 address"
                );
            }
        });
    }

    public function testPtrLookup() {
        Loop::run(function () {
            $result = yield Dns\query("8.8.4.4", Record::PTR);

            /** @var Record $record */
            $record = $result[0];
            $this->assertSame(Record::PTR, $record->getType());
            $this->assertSame("google-public-dns-b.google.com", (string)$record->getValue()->getName());
            $this->assertNotNull($record->getTtl());
        });
    }

    /**
     * Test that two concurrent requests to the same resource share the same request and do not result in two requests
     * being sent.
     */
    public function testRequestSharing() {
        Loop::run(function () {
            $promise1 = Dns\query("example.com", Record::A);
            $promise2 = Dns\query("example.com", Record::A);

            $this->assertSame($promise1, $promise2);
            $this->assertSame(yield $promise1, yield $promise2);
        });
    }

    public function provideHostnames() {
        return [
            ["google.com"],
            ["github.com"],
            ["stackoverflow.com"],
            ["blog.kelunik.com"], /* that's a CNAME to GH pages */
            ["localhost"],
            ["192.168.0.1"],
            ["::1"],
        ];
    }

    public function provideServers() {
        return [
            ["8.8.8.8"],
            ["8.8.8.8:53"],
        ];
    }
}
