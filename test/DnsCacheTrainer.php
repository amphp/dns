<?php declare(strict_types=1);

namespace Amp\Dns\Test;

use Amp\Cache\Cache;
use Amp\Dns\DnsRecord;
use PHPUnit\Framework\TestCase;

final class DnsCacheTrainer
{
    private Cache $mock;

    private array $operations = [];

    public function __construct(TestCase $testCase)
    {
        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->mock = $testCase->getMockBuilder(Cache::class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->disallowMockingUnknownTypes()
            ->getMock();
    }

    public function givenResponse(DnsRecord ...$records): void
    {
        $this->mock->method('get')->willReturnCallback(function ($key) use ($records) {
            $this->operations[] = ['get', $key];

            return \array_map(fn ($record) => [$record->getValue(), $record->getType()], $records);
        });
    }

    public function getOperations(): array
    {
        return $this->operations;
    }

    public function getMock(): Cache
    {
        return $this->mock;
    }
}
