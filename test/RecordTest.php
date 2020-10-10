<?php

namespace Amp\Dns\Test;

use Amp\Dns\Record;
use Amp\PHPUnit\AsyncTestCase;

class RecordTest extends AsyncTestCase
{
    public function testGetName()
    {
        $this->assertSame("A", Record::getName(Record::A));
    }

    public function testGetNameOnInvalidRecordType()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("65536 does not correspond to a valid record type (must be between 0 and 65535).");

        Record::getName(65536);
    }

    public function testGetNameOnUnknownRecordType()
    {
        $this->assertSame("unknown (1000)", Record::getName(1000));
    }
}
