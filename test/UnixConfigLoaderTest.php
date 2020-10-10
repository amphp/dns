<?php

namespace Amp\Dns\Test;

use Amp\Dns\ConfigException;
use Amp\Dns\UnixConfigLoader;
use Amp\PHPUnit\AsyncTestCase;

class UnixConfigLoaderTest extends AsyncTestCase
{
    public function test()
    {
        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv.conf");

        $result = $loader->loadConfig();

        $this->assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        $this->assertSame(30000, $result->getTimeout());
        $this->assertSame(3, $result->getAttempts());
        $this->assertEmpty($result->getSearchList());
        $this->assertSame(1, $result->getNdots());
        $this->assertFalse($result->isRotationEnabled());
    }

    public function testWithSearchList()
    {
        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv-search.conf");

        $result = $loader->loadConfig();

        $this->assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        $this->assertSame(30000, $result->getTimeout());
        $this->assertSame(3, $result->getAttempts());
        $this->assertSame(['local', 'local1', 'local2', 'local3', 'local4', 'local5'], $result->getSearchList());
        $this->assertSame(15, $result->getNdots());
        $this->assertFalse($result->isRotationEnabled());
    }

    public function testWithRotateOption()
    {
        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv-rotate.conf");

        $result = $loader->loadConfig();

        $this->assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        $this->assertSame(5000, $result->getTimeout());
        $this->assertSame(2, $result->getAttempts());
        $this->assertTrue($result->isRotationEnabled());
    }

    public function testWithNegativeOption()
    {
        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv-negative-option-values.conf");

        $result = $loader->loadConfig();

        $this->assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        $this->assertSame(5000, $result->getTimeout());
        $this->assertSame(2, $result->getAttempts());
        $this->assertSame(1, $result->getNdots());
    }

    public function testWithEnvironmentOverride()
    {
        \putenv("LOCALDOMAIN=local");
        \putenv("RES_OPTIONS=timeout:1 attempts:10 ndots:10 rotate");

        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv.conf");

        $result = $loader->loadConfig();

        $this->assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        $this->assertSame(['local'], $result->getSearchList());

        $this->assertSame(1000, $result->getTimeout());
        $this->assertSame(5, $result->getAttempts());
        $this->assertSame(10, $result->getNdots());
        $this->assertTrue($result->isRotationEnabled());
    }

    public function testNoDefaultsOnConfNotFound()
    {
        $this->expectException(ConfigException::class);
        (new UnixConfigLoader(__DIR__ . "/data/non-existent.conf"))->loadConfig();
    }
}
