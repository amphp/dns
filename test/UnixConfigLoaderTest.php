<?php

namespace Amp\Dns\Test;

use Amp\Dns\ConfigException;
use Amp\Dns\UnixConfigLoader;
use Amp\PHPUnit\AsyncTestCase;

class UnixConfigLoaderTest extends AsyncTestCase
{
    public function test(): void
    {
        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv.conf");

        $result = $loader->loadConfig();

        self::assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        self::assertSame(30.0, $result->getTimeout());
        self::assertSame(3, $result->getAttempts());
        self::assertSame(1, $result->getNdots());
        self::assertFalse($result->isRotationEnabled());

        $hostname = \gethostname();
        if (\str_contains($hostname, '.')) {
            self::assertSame([\substr($hostname, \strpos($hostname, '.') + 1)], $result->getSearchList());
        } else {
            self::assertEmpty($result->getSearchList());
        }
    }

    public function testWithSearchList(): void
    {
        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv-search.conf");

        $result = $loader->loadConfig();

        self::assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        self::assertSame(30.0, $result->getTimeout());
        self::assertSame(3, $result->getAttempts());
        self::assertSame(['local', 'local1', 'local2', 'local3', 'local4', 'local5'], $result->getSearchList());
        self::assertSame(15, $result->getNdots());
        self::assertFalse($result->isRotationEnabled());
    }

    public function testWithRotateOption(): void
    {
        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv-rotate.conf");

        $result = $loader->loadConfig();

        self::assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        self::assertSame(5.0, $result->getTimeout());
        self::assertSame(2, $result->getAttempts());
        self::assertTrue($result->isRotationEnabled());
    }

    public function testWithNegativeOption(): void
    {
        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv-negative-option-values.conf");

        $result = $loader->loadConfig();

        self::assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        self::assertSame(5.0, $result->getTimeout());
        self::assertSame(2, $result->getAttempts());
        self::assertSame(1, $result->getNdots());
    }

    public function testWithEnvironmentOverride(): void
    {
        \putenv("LOCALDOMAIN=local");
        \putenv("RES_OPTIONS=timeout:1 attempts:10 ndots:10 rotate");

        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv.conf");

        $result = $loader->loadConfig();

        self::assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        self::assertSame(['local'], $result->getSearchList());

        self::assertSame(1.0, $result->getTimeout());
        self::assertSame(5, $result->getAttempts());
        self::assertSame(10, $result->getNdots());
        self::assertTrue($result->isRotationEnabled());
    }

    public function testNoDefaultsOnConfNotFound(): void
    {
        $this->expectException(ConfigException::class);
        (new UnixConfigLoader(__DIR__ . "/data/non-existent.conf"))->loadConfig();
    }
}
