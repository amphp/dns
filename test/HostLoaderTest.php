<?php

namespace Amp\Dns\Test;

use Amp\Dns\HostLoader;
use Amp\Dns\Record;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use DaveRandom\LibDNS\HostsFile\HostsFile;

class HostLoaderTest extends TestCase {
    public function testReturnsHostsFileInstanceOnExistingFile() {
        Loop::run(function () {
            $loader = new HostLoader(__DIR__ . "/data/hosts");
            $this->assertInstanceOf(HostsFile::class, yield $loader->loadHosts());
        });
    }

    public function testReturnsEmptyErrorOnFileNotFound() {
        Loop::run(function () {
            $loader = new HostLoader(__DIR__ . "/data/hosts.not.found");
            $this->assertSame(null, yield $loader->loadHosts());
        });
    }
}
