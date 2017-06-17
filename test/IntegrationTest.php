<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use Amp\Dns\Record;
use Amp\Loop;
use Amp\PHPUnit\TestCase;

class IntegrationTest extends TestCase {
    /**
     * @group internet
     * @dataProvider provideHostnames
     */
    public function testResolve($hostname) {
        Loop::run(function () use ($hostname) {
            $result = yield Dns\resolve($hostname);

            list($addr, $type, $ttl) = $result[0];
            $inAddr = @\inet_pton($addr);
            $this->assertNotFalse(
                $inAddr,
                "Server name $hostname did not resolve to a valid IP address"
            );
        });
    }

    /**
     * @group internet
     * @dataProvider provideServers
     */
    public function testResolveWithCustomServer($server) {
        Loop::run(function () use ($server) {
            $result = yield Dns\resolve("google.com", [
                "server" => $server,
            ]);

            list($addr) = $result[0];
            $inAddr = @\inet_pton($addr);
            $this->assertNotFalse(
                $inAddr,
                "Server name google.com did not resolve to a valid IP address via $server"
            );
        });
    }

    public function testPtrLookup() {
        Loop::run(function () {
            $result = yield Dns\query("8.8.4.4", Record::PTR);

            list($addr, $type) = $result[0];
            $this->assertSame("google-public-dns-b.google.com", $addr);
            $this->assertSame(Record::PTR, $type);
        });
    }

    public function provideHostnames() {
        return [
            ["google.com"],
            ["github.com"],
            ["stackoverflow.com"],
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
