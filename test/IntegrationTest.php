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
                list($addr, $mode) = (yield \Amp\Dns\resolve($name));
                $inAddr = @\inet_pton($addr);
                $this->assertNotFalse(
                    $inAddr,
                    "Server name $name did not resolve to a valid IP address"
                );
                if (isset($inAddr[15])) {
                    $this->assertSame(
                        \Amp\Dns\MODE_INET6,
                        $mode,
                        "Returned mode parameter did not match expected MODE_INET6"
                    );
                } else {
                    $this->assertSame(
                        \Amp\Dns\MODE_INET4,
                        $mode,
                        "Returned mode parameter did not match expected MODE_INET4"
                    );
                }
            }
        });
    }
}
