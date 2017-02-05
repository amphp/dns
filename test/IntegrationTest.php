<?php

namespace Amp\Dns\Test;

class IntegrationTest extends \PHPUnit_Framework_TestCase {
    protected function setUp() {
        \Amp\reactor(\Amp\driver());
        \Amp\Dns\resolver(\Amp\Dns\driver());
    }

    /**
     * @group internet
     * @dataProvider provideHostnames
     */
    public function testResolve($hostname) {
        \Amp\run(function () use ($hostname) {
            $result = (yield \Amp\Dns\resolve($hostname));
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
        \Amp\run(function () use ($server) {
            $result = (yield \Amp\Dns\resolve("google.com", [
				"server" => $server
			]));
            list($addr, $type, $ttl) = $result[0];
            $inAddr = @\inet_pton($addr);
            $this->assertNotFalse(
                $inAddr,
                "Server name google.com did not resolve to a valid IP address via $server"
            );
        });
    }

    public function testPtrLoopup() {
        \Amp\run(function () {
            $result = (yield \Amp\Dns\query("8.8.4.4", \Amp\Dns\Record::PTR));
            list($addr, $type) = $result[0];
            $this->assertSame($addr, "google-public-dns-b.google.com");
            $this->assertSame($type, \Amp\Dns\Record::PTR);
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
