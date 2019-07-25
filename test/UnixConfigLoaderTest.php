<?php

namespace Amp\Dns\Test;

use Amp\Dns\Config;
use Amp\Dns\ConfigException;
use Amp\Dns\UnixConfigLoader;
use Amp\PHPUnit\TestCase;
use function Amp\Promise\wait;

class UnixConfigLoaderTest extends TestCase
{
    public function test()
    {
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

    public function testWithSearchList()
    {
        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv-search.conf");

        /** @var Config $result */
        $result = wait($loader->loadConfig());

        $this->assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        $this->assertSame(5000, $result->getTimeout());
        $this->assertSame(3, $result->getAttempts());
        $this->assertSame(['local'], $result->getSearchList());
        $this->assertSame(15, $result->getNdots());
    }

    public function testNoDefaultsOnConfNotFound()
    {
        $this->expectException(ConfigException::class);
        wait((new UnixConfigLoader(__DIR__ . "/data/non-existent.conf"))->loadConfig());
    }
}
