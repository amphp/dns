<?php declare(strict_types=1);

namespace Amp\Dns\Test;

use Amp\Dns\DnsRecord;
use Amp\Dns\HostLoader;
use Amp\PHPUnit\AsyncTestCase;

class HostLoaderTest extends AsyncTestCase
{
    public function testIgnoresCommentsAndParsesBasicEntry(): void
    {
        $loader = new HostLoader(__DIR__ . "/data/hosts");
        self::assertSame([
            DnsRecord::A => [
                "localhost" => "127.0.0.1",
            ],
        ], $loader->loadHosts());
    }

    public function testReturnsEmptyErrorOnFileNotFound(): void
    {
        $loader = new HostLoader(__DIR__ . "/data/hosts.not.found");
        self::assertSame([], $loader->loadHosts());
    }

    public function testIgnoresInvalidNames(): void
    {
        $loader = new HostLoader(__DIR__ . "/data/hosts.invalid.name");
        self::assertSame([
            DnsRecord::A => [
                "localhost" => "127.0.0.1",
            ],
        ], $loader->loadHosts());
    }
}
