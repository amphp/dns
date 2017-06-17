<?php

namespace Amp\Dns\Test;

use Amp\Coroutine;
use Amp\PHPUnit\TestCase;
use ReflectionObject;

class ResolvConfTest extends TestCase {
    public function test() {
        $reflector = new ReflectionObject(\Amp\Dns\resolver());
        $method = $reflector->getMethod("loadResolvConf");
        $method->setAccessible(true);

        $result = \Amp\Promise\wait(new Coroutine($method->invoke(\Amp\Dns\resolver(), __DIR__ . "/data/resolv.conf")));

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
        $reflector = new ReflectionObject(\Amp\Dns\resolver());
        $method = $reflector->getMethod("loadResolvConf");
        $method->setAccessible(true);

        $result = \Amp\Promise\wait(new Coroutine($method->invoke(\Amp\Dns\resolver(), __DIR__ . "/data/invalid.conf")));

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
