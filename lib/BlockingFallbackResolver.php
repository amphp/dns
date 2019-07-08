<?php

namespace Amp\Dns;

use Amp\Failure;
use Amp\Promise;
use Amp\Success;

class BlockingFallbackResolver implements Resolver
{
    public function resolve(string $name, int $typeRestriction = null): Promise
    {
        if (!\in_array($typeRestriction, [Record::A, null], true)) {
            return new Failure(new DnsException("Query for '{$name}' failed, because loading the system's DNS configuration failed and querying records other than A records isn't supported in blocking fallback mode."));
        }

        return $this->query($name, Record::A);
    }

    public function query(string $name, int $type): Promise
    {
        if ($type !== Record::A) {
            return new Failure(new DnsException("Query for '{$name}' failed, because loading the system's DNS configuration failed and querying records other than A records isn't supported in blocking fallback mode."));
        }

        $result = \gethostbynamel($name);
        if ($result === false) {
            return new Failure(new DnsException("Query for '{$name}' failed, because loading the system's DNS configuration failed and blocking fallback via gethostbynamel() failed, too."));
        }

        if ($result === []) {
            return new Failure(new NoRecordException("No records returned for '{$name}' using blocking fallback mode."));
        }

        $records = [];

        foreach ($result as $record) {
            $records[] = new Record($record, Record::A, null);
        }

        return new Success($records);
    }
}
