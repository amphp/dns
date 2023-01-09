<?php declare(strict_types=1);

namespace Amp\Dns\Test;

use Amp\Cache\NullCache;
use Amp\Dns;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\UnixDnsConfigLoader;
use Amp\Dns\WindowsDnsConfigLoader;
use Amp\PHPUnit\AsyncTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use function Amp\async;

class IntegrationTest extends AsyncTestCase
{
    /**
     * @group internet
     * @dataProvider provideHostnames
     */
    public function testResolve(string $hostname): void
    {
        $result = Dns\resolve($hostname);

        $record = $result[0];
        $inAddr = @\inet_pton($record->getValue());
        self::assertNotFalse(
            $inAddr,
            "Server name {$hostname} did not resolve to a valid IP address"
        );
    }

    /**
     * @group internet
     */
    public function testWorksAfterConfigReload(): void
    {
        Dns\query("google.com", DnsRecord::A);
        self::assertInstanceOf(Dns\DnsConfig::class, Dns\resolver()->reloadConfig());
        self::assertIsArray(Dns\query("example.com", DnsRecord::A));
    }

    public function testResolveIPv4only(): void
    {
        $records = Dns\resolve("google.com", DnsRecord::A);

        foreach ($records as $record) {
            self::assertSame(DnsRecord::A, $record->getType());
            $inAddr = @\inet_pton($record->getValue());
            self::assertNotFalse(
                $inAddr,
                "Server name google.com did not resolve to a valid IP address"
            );
        }
    }

    public function testResolveIPv6only(): void
    {
        $records = Dns\resolve("google.com", DnsRecord::AAAA);

        foreach ($records as $record) {
            self::assertSame(DnsRecord::AAAA, $record->getType());
            $inAddr = @\inet_pton($record->getValue());
            self::assertNotFalse(
                $inAddr,
                "Server name google.com did not resolve to a valid IP address"
            );
        }
    }

    public function testResolveUsingSearchList(): void
    {
        $configLoader = \stripos(PHP_OS, "win") === 0
            ? new WindowsDnsConfigLoader()
            : new UnixDnsConfigLoader();
        $config = $configLoader->loadConfig();
        $config = $config->withSearchList(['foobar.invalid', 'kelunik.com']);
        $config = $config->withNdots(1);
        /** @var Dns\DnsConfigLoader|MockObject $configLoader */
        $configLoader = $this->createMock(Dns\DnsConfigLoader::class);
        $configLoader->expects(self::once())
            ->method('loadConfig')
            ->willReturn($config);

        Dns\resolver(new Dns\Rfc1035StubDnsResolver(null, $configLoader));
        $result = Dns\resolve('blog');

        $record = $result[0];
        $inAddr = @\inet_pton($record->getValue());
        self::assertNotFalse(
            $inAddr,
            "Server name blog.kelunik.com did not resolve to a valid IP address"
        );

        $result = Dns\query('blog.kelunik.com', Dns\DnsRecord::A);
        $record = $result[0];
        self::assertSame($inAddr, @\inet_pton($record->getValue()));
    }

    public function testFailResolveRootedDomainWhenSearchListDefined(): void
    {
        $configLoader = \stripos(PHP_OS, "win") === 0
            ? new WindowsDnsConfigLoader()
            : new UnixDnsConfigLoader();
        $config = $configLoader->loadConfig();
        $config = $config->withSearchList(['kelunik.com']);
        $config = $config->withNdots(1);
        /** @var Dns\DnsConfigLoader|MockObject $configLoader */
        $configLoader = $this->createMock(Dns\DnsConfigLoader::class);
        $configLoader->expects(self::once())
            ->method('loadConfig')
            ->willReturn($config);

        Dns\resolver(new Dns\Rfc1035StubDnsResolver(null, $configLoader));
        $this->expectException(DnsException::class);
        Dns\resolve('blog.');
    }

    public function testResolveWithRotateList(): void
    {
        /** @var Dns\DnsConfigLoader|MockObject $configLoader */
        $configLoader = $this->createMock(Dns\DnsConfigLoader::class);
        $config = new Dns\DnsConfig([
            '208.67.222.220:53', // Opendns, US
            '195.243.214.4:53', // Deutsche Telecom AG, DE
        ]);
        $config = $config->withRotationEnabled(true);
        $configLoader->expects(self::once())
            ->method('loadConfig')
            ->willReturn($config);

        $resolver = new Dns\Rfc1035StubDnsResolver(new NullCache(), $configLoader);

        /** @var DnsRecord $record1 */
        [$record1] = $resolver->query('google.com', Dns\DnsRecord::A);
        /** @var DnsRecord $record2 */
        [$record2] = $resolver->query('google.com', Dns\DnsRecord::A);

        self::assertNotSame($record1->getValue(), $record2->getValue());
    }

    public function testPtrLookup(): void
    {
        $result = Dns\query("8.8.4.4", DnsRecord::PTR);

        $record = $result[0];
        self::assertSame("dns.google", $record->getValue());
        self::assertNotNull($record->getTtl());
        self::assertSame(DnsRecord::PTR, $record->getType());
    }

    /**
     * Test that two concurrent requests to the same resource share the same request and do not result in two requests
     * being sent.
     */
    public function testRequestSharing(): void
    {
        $promise1 = async(fn () => Dns\query("example.com", DnsRecord::A));
        $promise2 = async(fn () => Dns\query("example.com", DnsRecord::A));

        self::assertSame($promise1->await(), $promise2->await());
    }

    public function provideHostnames(): array
    {
        return [
            ["google.com"],
            ["github.com"],
            ["stackoverflow.com"],
            ["blog.kelunik.com"], /* that's a CNAME to GH pages */
            ["localhost"],
            ["192.168.0.1"],
            ["::1"],
            ["dns.google."], /* that's rooted domain name - cannot use searchList */
        ];
    }

    public function provideServers(): array
    {
        return [
            ["8.8.8.8"],
            ["8.8.8.8:53"],
        ];
    }
}
