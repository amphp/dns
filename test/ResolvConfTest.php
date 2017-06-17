<?php

namespace Amp\Dns\Test;

use Amp\Coroutine;
use Amp\Dns\Config;
use Amp\Dns\ConfigException;
use Amp\Dns\UnixConfigLoader;
use Amp\PHPUnit\TestCase;
use ReflectionObject;
use function Amp\Promise\wait;

class ResolvConfTest extends TestCase {
    public function test() {
        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv.conf");

        /** @var Config $result */
        $result = wait($loader->loadConfig());

        $this->assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        $this->assertSame(5000, $result->getTimeout());
        $this->assertSame(3, $result->getAttempts());
    }

    public function testDefaultsOnConfNotFound() {
        $this->expectException(ConfigException::class);
        wait((new UnixConfigLoader(__DIR__ . "/data/non-existent.conf"))->loadConfig());
    }
}
