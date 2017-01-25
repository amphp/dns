<?php

namespace Amp\Dns\Test;

use AsyncInterop\Loop;

class IntegrationTest extends \PHPUnit_Framework_TestCase {
    /**
     * @group internet
     * @dataProvider provideHostnames
     */
    public function testResolve($hostname) {
        Loop::execute(\Amp\wrap(function () use ($hostname) {
            $result = (yield \Amp\Dns\resolve($hostname));
            list($addr, $type, $ttl) = $result[0];
            $inAddr = @\inet_pton($addr);
            $this->assertNotFalse(
                $inAddr,
                "Server name $hostname did not resolve to a valid IP address"
            );
        }));
    }

    /**
     * @group internet
     * @dataProvider provideServers
     */
    public function testResolveWithCustomServer($server) {
        Loop::execute(\Amp\wrap(function () use ($server) {
            $result = (yield \Amp\Dns\resolve("google.com", [
                "server" => $server
            ]));
            list($addr, $type, $ttl) = $result[0];
            $inAddr = @\inet_pton($addr);
            $this->assertNotFalse(
                $inAddr,
                "Server name google.com did not resolve to a valid IP address via $server"
            );
        }));
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
