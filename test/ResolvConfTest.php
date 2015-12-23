<?php

namespace Amp\Dns\Test;

class ResolvConfTest extends \PHPUnit_Framework_TestCase {
    public function test() {
        $result = \Amp\wait(\Amp\resolve(\Amp\Dns\__loadResolvConf(__DIR__ . "/data/resolv.conf")));

        $this->assertSame([
            "nameservers" => [
                "127.0.0.1:53",
                "[2001:4860:4860::8888]:53"
            ],
            "timeout" => 5000,
            "attempts" => 3,
        ], $result);
    }

    public function testDefaultsOnConfNotFound() {
        $result = \Amp\wait(\Amp\resolve(\Amp\Dns\__loadResolvConf(__DIR__ . "/data/invalid.conf")));

        $this->assertSame([
            "nameservers" => [
                "8.8.8.8:53",
                "8.8.4.4:53"
            ],
            "timeout" => 3000,
            "attempts" => 2,
        ], $result);
    }
}