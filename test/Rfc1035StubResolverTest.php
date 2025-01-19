<?php declare(strict_types=1);

namespace Amp\Dns\Test;

use Amp\Cancellation;
use Amp\Dns\DnsConfig;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\InvalidNameException;
use Amp\Dns\MissingDnsRecordException;
use Amp\Dns\Rfc1035StubDnsResolver;
use Amp\Dns\StaticDnsConfigLoader;
use Amp\PHPUnit\AsyncTestCase;

class Rfc1035StubResolverTest extends AsyncTestCase
{
    private DnsCacheTrainer $cacheTrainer;
    private ?DnsConfig $dnsConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheTrainer = new DnsCacheTrainer($this);
    }

    public function testResolveSecondParameterAcceptedValues(): void
    {
        $this->expectException(\Error::class);
        $this->whenResolve("abc.de", DnsRecord::TXT);
    }

    public function testIpAsArgumentWithIPv4Restriction(): void
    {
        $this->expectException(DnsException::class);
        $this->whenResolve("::1", DnsRecord::A);
    }

    public function testIpAsArgumentWithIPv6Restriction(): void
    {
        $this->expectException(DnsException::class);
        $this->whenResolve("127.0.0.1", DnsRecord::AAAA);
    }

    public function testInvalidName(): void
    {
        $this->expectException(InvalidNameException::class);
        $this->whenResolve("go@gle.com", DnsRecord::A);
    }

    public function testSearchListDot(): void
    {
        $this->dnsConfig = (new DnsConfig(['127.0.0.1']))
            ->withNdots(1)
            ->withSearchList(['.']);

        $this->cacheTrainer->givenResponse(new DnsRecord('1.2.3.4', DnsRecord::A));

        $this->whenResolve('foobar');

        $this->assertSame([
            ['get', 'amphp.dns.foobar#1'],
            ['get', 'amphp.dns.foobar#28'],
        ], $this->cacheTrainer->getOperations());
    }

    public function testSearchListDomain(): void
    {
        $this->dnsConfig = (new DnsConfig(['127.0.0.1']))
            ->withNdots(1)
            ->withSearchList(['search']);

        $this->cacheTrainer->givenResponse(new DnsRecord('1.2.3.4', DnsRecord::A));

        $this->whenResolve('foobar');

        $this->assertSame([
            ['get', 'amphp.dns.foobar.search#1'],
            ['get', 'amphp.dns.foobar.search#28'],
        ], $this->cacheTrainer->getOperations());
    }

    public function testSearchListDomainDots(): void
    {
        $this->dnsConfig = (new DnsConfig(['127.0.0.1']))
            ->withNdots(1)
            ->withSearchList(['search']);

        $this->cacheTrainer->givenResponse(new DnsRecord('1.2.3.4', DnsRecord::A));

        $this->whenResolve('foo.bar');

        $this->assertSame([
            ['get', 'amphp.dns.foo.bar#1'],
            ['get', 'amphp.dns.foo.bar#28'],
        ], $this->cacheTrainer->getOperations());
    }

    public function testNonExistingDomain(): void
    {
        $this->dnsConfig = new DnsConfig(['8.8.8.8:53']);

        $this->expectException(MissingDnsRecordException::class);
        $this->expectExceptionMessage('No records returned for');

        $this->whenResolve('not.existing.domain.com');
    }

    private function whenResolve(string $name, ?int $typeRestriction = null, ?Cancellation $cancellation = null): void
    {
        $configLoader = $this->dnsConfig !== null ? new StaticDnsConfigLoader($this->dnsConfig) : null;

        $resolver = new Rfc1035StubDnsResolver($this->cacheTrainer->getMock(), $configLoader);
        $resolver->resolve($name, $typeRestriction, $cancellation);
    }
}
