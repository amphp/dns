<?php

namespace Amp\Dns\Test;

use Amp\Cache\NullCache;
use Amp\Dns;
use Amp\Dns\Record;
use Amp\Dns\Rfc1035StubResolver;
use Amp\Dns\UnixConfigLoader;
use Amp\Dns\WindowsConfigLoader;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Success;
use PHPUnit\Framework\MockObject\MockObject;

class IntegrationTest extends TestCase
{
    /**
     * @param string $hostname
     * @group internet
     * @dataProvider provideHostnames
     */
    public function testResolve($hostname)
    {
        Loop::run(function () use ($hostname) {
            $result = yield Dns\resolve($hostname);

            /** @var Record $record */
            $record = $result[0];
            $inAddr = @\inet_pton($record->getValue());
            $this->assertNotFalse(
                $inAddr,
                "Server name {$hostname} did not resolve to a valid IP address"
            );
        });
    }

    /**
     * @group internet
     */
    public function testWorksAfterConfigReload()
    {
        Loop::run(function () {
            yield Dns\query("google.com", Record::A);
            $this->assertNull(yield Dns\resolver()->reloadConfig());
            $this->assertInternalType("array", yield Dns\query("example.com", Record::A));
        });
    }

    public function testResolveIPv4only()
    {
        Loop::run(function () {
            $records = yield Dns\resolve("google.com", Record::A);

            /** @var Record $record */
            foreach ($records as $record) {
                $this->assertSame(Record::A, $record->getType());
                $inAddr = @\inet_pton($record->getValue());
                $this->assertNotFalse(
                    $inAddr,
                    "Server name google.com did not resolve to a valid IP address"
                );
            }
        });
    }

    public function testResolveIPv6only()
    {
        Loop::run(function () {
            $records = yield Dns\resolve("google.com", Record::AAAA);

            /** @var Record $record */
            foreach ($records as $record) {
                $this->assertSame(Record::AAAA, $record->getType());
                $inAddr = @\inet_pton($record->getValue());
                $this->assertNotFalse(
                    $inAddr,
                    "Server name google.com did not resolve to a valid IP address"
                );
            }
        });
    }

    public function testResolveUsingSearchList()
    {
        Loop::run(function () {
            $configLoader = \stripos(PHP_OS, "win") === 0
                ? new WindowsConfigLoader()
                : new UnixConfigLoader();
            /** @var Dns\Config $config */
            $config = yield $configLoader->loadConfig();
            /** @var Dns\ConfigLoader|MockObject $configLoader */
            $configLoader = $this->createMock(Dns\ConfigLoader::class);
            $configLoader->expects($this->once())
                ->method('loadConfig')
                ->willReturn(new Success(new Dns\Config(
                    $config->getNameservers(),
                    $config->getKnownHosts(),
                    $config->getTimeout(),
                    $config->getAttempts(),
                    ['kelunik.com'],
                    1
                )));

            Dns\resolver(new Dns\Rfc1035StubResolver(null, $configLoader));
            $result = yield Dns\resolve('blog');

            /** @var Record $record */
            $record = $result[0];
            $inAddr = @\inet_pton($record->getValue());
            $this->assertNotFalse(
                $inAddr,
                "Server name blog.kelunik.com did not resolve to a valid IP address"
            );
        });
    }

    public function testResolveWithRotateList()
    {
        Loop::run(function () {
            /** @var Dns\ConfigLoader|MockObject $configLoader */
            $configLoader = $this->createMock(Dns\ConfigLoader::class);
            $configLoader->expects($this->once())
                ->method('loadConfig')
                ->willReturn(new Success(new Dns\Config(
                    [
                        '208.67.222.220:53', // Opendns
                        '195.243.214.4:53', // Deutche Telecom AG
                    ],
                    [],
                    5000,
                    3,
                    [],
                    1,
                    true
                )));

            $resolver = new Dns\Rfc1035StubResolver(new NullCache(), $configLoader);

            /** @var Record $record1 */
            list($record1) = yield $resolver->query('facebook.com', Dns\Record::A);
            /** @var Record $record2 */
            list($record2) = yield $resolver->query('facebook.com', Dns\Record::A);


            $this->assertNotSame($record1->getValue(), $record2->getValue());
        });
    }

    public function testPtrLookup()
    {
        Loop::run(function () {
            $result = yield Dns\query("8.8.4.4", Record::PTR);

            /** @var Record $record */
            $record = $result[0];
            $this->assertSame("dns.google", $record->getValue());
            $this->assertNotNull($record->getTtl());
            $this->assertSame(Record::PTR, $record->getType());
        });
    }

    /**
     * Test that two concurrent requests to the same resource share the same request and do not result in two requests
     * being sent.
     */
    public function testRequestSharing()
    {
        Loop::run(function () {
            $promise1 = Dns\query("example.com", Record::A);
            $promise2 = Dns\query("example.com", Record::A);

            $this->assertSame($promise1, $promise2);
            $this->assertSame(yield $promise1, yield $promise2);
        });
    }

    public function provideHostnames()
    {
        return [
            ["google.com"],
            ["github.com"],
            ["stackoverflow.com"],
            ["blog.kelunik.com"], /* that's a CNAME to GH pages */
            ["localhost"],
            ["192.168.0.1"],
            ["::1"],
        ];
    }

    public function provideServers()
    {
        return [
            ["8.8.8.8"],
            ["8.8.8.8:53"],
        ];
    }
}
