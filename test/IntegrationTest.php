<?php

namespace Amp\Dns\Test;

class IntegrationTest extends \PHPUnit_Framework_TestCase {
    protected function setUp() {
        \Amp\reactor(\Amp\driver());
    }

    /**
     * @group internet
     */
    public function testResolve() {
        \Amp\run(function () {
            $names = [
                "google.com",
                "github.com",
                "stackoverflow.com",
                "localhost",
                "192.168.0.1",
                "::1",
            ];

            foreach ($names as $name) {
                $result = (yield \Amp\Dns\resolve($name));
                list($addr, $type, $ttl) = $result[0];
                $inAddr = @\inet_pton($addr);
                $this->assertNotFalse(
                    $inAddr,
                    "Server name $name did not resolve to a valid IP address"
                );
            }
        });
    }
}
