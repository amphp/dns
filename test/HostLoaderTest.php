<?php

namespace Amp\Dns\Test;

use Amp\Dns\HostLoader;
use Amp\Dns\Record;
use Amp\Loop;
use Amp\PHPUnit\TestCase;

class HostLoaderTest extends TestCase {
    public function testIgnoresCommentsAndParsesBasicEntry() {
        Loop::run(function () {
            $loader = new HostLoader(__DIR__ . "/data/hosts");
            $this->assertSame([
                Record::A => [
                    "localhost" => "127.0.0.1",
                ],
            ], yield $loader->loadHosts());
        });
    }

    public function testReturnsEmptyErrorOnFileNotFound() {
        Loop::run(function () {
            $loader = new HostLoader(__DIR__ . "/data/hosts.not.found");
            $this->assertSame([], yield $loader->loadHosts());
        });
    }

    public function testIgnoresInvalidNames() {
        Loop::run(function () {
            $loader = new HostLoader(__DIR__ . "/data/hosts.invalid.name");
            $this->assertSame([
                Record::A => [
                    "localhost" => "127.0.0.1",
                ],
            ], yield $loader->loadHosts());
        });
    }
}
