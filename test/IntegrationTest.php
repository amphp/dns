<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use Amp\Dns\Record;
use Amp\Loop;
use Amp\PHPUnit\TestCase;

class IntegrationTest extends TestCase {
    /**
     * @param string $hostname
     * @group internet
     * @dataProvider provideHostnames
     */
    public function testResolve($hostname) {
        Loop::run(function () use ($hostname) {
            $result = yield Dns\resolve($hostname);

            /** @var Record $record */
            $record = $result[0];
            $inAddr = @\inet_pton($record->getValue());
            $this->assertNotFalse(
                $inAddr,
                "Server name $hostname did not resolve to a valid IP address"
            );
        });
    }

    /**
     * @param string $hostname
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
                $inAddr = @\inet_pton($record->getValue());
                $this->assertNotFalse(
                    $inAddr,
                    "Server name google.com did not resolve to a valid IP address"
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
                $inAddr = @\inet_pton($record->getValue());
                $this->assertNotFalse(
                    $inAddr,
                    "Server name google.com did not resolve to a valid IP address"
                );
            }
        });
    }

    public function testPtrLookup() {
        Loop::run(function () {
            $result = yield Dns\query("8.8.4.4", Record::PTR);

            /** @var Record $record */
            $record = $result[0];
            $this->assertSame("google-public-dns-b.google.com", $record->getValue());
            $this->assertNotNull($record->getTtl());
            $this->assertSame(Record::PTR, $record->getType());
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
