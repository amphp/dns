<?php declare(strict_types=1);

namespace Amp\Dns\Test;

use Amp\Dns\Record;
use Amp\PHPUnit\AsyncTestCase;

class RecordTest extends AsyncTestCase
{
    public function testGetName(): void
    {
        self::assertSame("A", Record::getName(Record::A));
    }

    public function testGetNameOnInvalidRecordType(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("65536 does not correspond to a valid record type (must be between 0 and 65535).");

        Record::getName(65536);
    }

    public function testGetNameOnUnknownRecordType(): void
    {
        self::assertSame("unknown (1000)", Record::getName(1000));
    }
}
