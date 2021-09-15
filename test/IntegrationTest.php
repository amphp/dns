<?php

namespace Amp\Dns\Test;

use Amp\Cache\NullCache;
use Amp\Dns;
use Amp\Dns\DnsException;
use Amp\Dns\Record;
use Amp\Dns\UnixConfigLoader;
use Amp\Dns\WindowsConfigLoader;
use Amp\PHPUnit\AsyncTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use function Amp\Future\spawn;

class IntegrationTest extends AsyncTestCase
{
    /**
     * @param string $hostname
     *
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
        Dns\query("google.com", Record::A);
        self::assertInstanceOf(Dns\Config::class, Dns\resolver()->reloadConfig());
        self::assertIsArray(Dns\query("example.com", Record::A));
    }

    public function testResolveIPv4only(): void
    {
        $records = Dns\resolve("google.com", Record::A);

        foreach ($records as $record) {
            self::assertSame(Record::A, $record->getType());
            $inAddr = @\inet_pton($record->getValue());
            self::assertNotFalse(
                $inAddr,
                "Server name google.com did not resolve to a valid IP address"
            );
        }
    }

    public function testResolveIPv6only(): void
    {
        $records = Dns\resolve("google.com", Record::AAAA);

        foreach ($records as $record) {
            self::assertSame(Record::AAAA, $record->getType());
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
            ? new WindowsConfigLoader()
            : new UnixConfigLoader();
        $config = $configLoader->loadConfig();
        $config = $config->withSearchList(['foobar.invalid', 'kelunik.com']);
        $config = $config->withNdots(1);
        /** @var Dns\ConfigLoader|MockObject $configLoader */
        $configLoader = $this->createMock(Dns\ConfigLoader::class);
        $configLoader->expects(self::once())
            ->method('loadConfig')
            ->willReturn($config);

        Dns\resolver(new Dns\Rfc1035StubResolver(null, $configLoader));
        $result = Dns\resolve('blog');

        $record = $result[0];
        $inAddr = @\inet_pton($record->getValue());
        self::assertNotFalse(
            $inAddr,
            "Server name blog.kelunik.com did not resolve to a valid IP address"
        );

        $result = Dns\query('blog.kelunik.com', Dns\Record::A);
        $record = $result[0];
        self::assertSame($inAddr, @\inet_pton($record->getValue()));
    }

    public function testFailResolveRootedDomainWhenSearchListDefined(): void
    {
        $configLoader = \stripos(PHP_OS, "win") === 0
            ? new WindowsConfigLoader()
            : new UnixConfigLoader();
        $config = $configLoader->loadConfig();
        $config = $config->withSearchList(['kelunik.com']);
        $config = $config->withNdots(1);
        /** @var Dns\ConfigLoader|MockObject $configLoader */
        $configLoader = $this->createMock(Dns\ConfigLoader::class);
        $configLoader->expects(self::once())
            ->method('loadConfig')
            ->willReturn($config);

        Dns\resolver(new Dns\Rfc1035StubResolver(null, $configLoader));
        $this->expectException(DnsException::class);
        Dns\resolve('blog.');
    }

    public function testResolveWithRotateList(): void
    {
        /** @var Dns\ConfigLoader|MockObject $configLoader */
        $configLoader = $this->createMock(Dns\ConfigLoader::class);
        $config = new Dns\Config([
            '208.67.222.220:53', // Opendns, US
            '195.243.214.4:53', // Deutche Telecom AG, DE
        ]);
        $config = $config->withRotationEnabled(true);
        $configLoader->expects(self::once())
            ->method('loadConfig')
            ->willReturn($config);

        $resolver = new Dns\Rfc1035StubResolver(new NullCache(), $configLoader);

        /** @var Record $record1 */
        [$record1] = $resolver->query('facebook.com', Dns\Record::A);
        /** @var Record $record2 */
        [$record2] = $resolver->query('facebook.com', Dns\Record::A);

        self::assertNotSame($record1->getValue(), $record2->getValue());
    }

    public function testPtrLookup(): void
    {
        $result = Dns\query("8.8.4.4", Record::PTR);

        $record = $result[0];
        self::assertSame("dns.google", $record->getValue());
        self::assertNotNull($record->getTtl());
        self::assertSame(Record::PTR, $record->getType());
    }

    /**
     * Test that two concurrent requests to the same resource share the same request and do not result in two requests
     * being sent.
     */
    public function testRequestSharing(): void
    {
        $promise1 = spawn(fn () => Dns\query("example.com", Record::A));
        $promise2 = spawn(fn () => Dns\query("example.com", Record::A));

        self::assertSame($promise1->join(), $promise2->join());
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
