<?php

namespace Amp\Dns\Test;

use Amp\Dns\Config;
use Amp\Dns\ConfigException;
use Amp\PHPUnit\AsyncTestCase;

class ConfigTest extends AsyncTestCase
{
    /**
     * @param string[] $nameservers Valid server array.
     *
     * @dataProvider provideValidServers
     */
    public function testAcceptsValidServers(array $nameservers): void
    {
        self::assertInstanceOf(Config::class, new Config($nameservers));
    }

    public function provideValidServers(): array
    {
        return [
            [["127.1.1.1"]],
            [["127.1.1.1:1"]],
            [["[::1]:52"]],
            [["[::1]"]],
        ];
    }

    /**
     * @param string[] $nameservers Invalid server array.
     *
     * @dataProvider provideInvalidServers
     */
    public function testRejectsInvalidServers(array $nameservers): void
    {
        $this->expectException(ConfigException::class);
        new Config($nameservers);
    }

    public function provideInvalidServers(): array
    {
        return [
            [[]],
            [["foobar"]],
            [["foobar.com"]],
            [["127.1.1"]],
            [["127.1.1.1.1"]],
            [["126.0.0.5", "foobar"]],
            [["42"]],
            [["::1"]],
            [["::1:53"]],
            [["[::1]:"]],
            [["[::1]:76235"]],
            [["[::1]:0"]],
            [["[::1]:-1"]],
            [["[::1:51"]],
            [["[::1]:abc"]],
        ];
    }

    public function testInvalidTimeout(): void
    {
        $this->expectException(ConfigException::class);
        new Config(["127.0.0.1"], [], -1);
    }

    public function testInvalidAttempts(): void
    {
        $this->expectException(ConfigException::class);
        new Config(["127.0.0.1"], [], 500, 0);
    }

    public function testInvalidNdots(): void
    {
        $this->expectException(ConfigException::class);
        $config = new Config(["127.0.0.1"]);
        $config->withNdots(-1);
    }

    public function testNdots(): void
    {
        $config = new Config(["127.0.0.1"]);
        $config = $config->withNdots(1);
        self::assertSame(1, $config->getNdots());
    }

    public function testSearchList(): void
    {
        $config = new Config(["127.0.0.1"]);
        $config = $config->withSearchList(['local']);
        self::assertSame(['local'], $config->getSearchList());
    }

    public function testRotationEnabled(): void
    {
        $config = new Config(["127.0.0.1"]);
        $config = $config->withRotationEnabled(true);
        self::assertTrue($config->isRotationEnabled());
    }

    public function testRotationDisabled(): void
    {
        $config = new Config(["127.0.0.1"]);
        self::assertFalse($config->isRotationEnabled());
    }
}
