<?php

namespace Amp\Dns\Test;

use Amp\Dns\AsyncConfigFileReader;
use Amp\Dns\BlockingConfigFileReader;
use Amp\Dns\ConfigException;
use Amp\Dns\ConfigFileReader;
use Amp\PHPUnit\TestCase;
use Amp\Promise;

class ConfigFileReaderTest extends TestCase {
    /**
     * @dataProvider getConfigReaders
     * @param ConfigFileReader $reader
     */
    public function testValidPath(ConfigFileReader $reader) {
        $path = __DIR__ . "/data/resolv.conf";
        $contents = Promise\wait($reader->read($path));
        $this->assertStringEqualsFile($path, $contents);
    }

    /**
     * @dataProvider getConfigReaders
     * @param ConfigFileReader $reader
     */
    public function testInvalidPath(ConfigFileReader $reader) {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Could not read configuration file");
        Promise\wait($reader->read(__DIR__ . "/data/non-existent.conf"));
    }

    public function getConfigReaders(): array {
        return [
            [new BlockingConfigFileReader],
            [new AsyncConfigFileReader],
        ];
    }
}
