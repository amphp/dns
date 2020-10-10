<?php

namespace Amp\Dns;

final class BlockingFallbackResolver implements Resolver
{
    public function resolve(string $name, int $typeRestriction = null): array
    {
        if (!\in_array($typeRestriction, [Record::A, null], true)) {
            throw new DnsException("Query for '{$name}' failed, because loading the system's DNS configuration failed and querying records other than A records isn't supported in blocking fallback mode.");
        }

        return $this->query($name, Record::A);
    }

    public function query(string $name, int $type): array
    {
        if ($type !== Record::A) {
            throw new DnsException("Query for '{$name}' failed, because loading the system's DNS configuration failed and querying records other than A records isn't supported in blocking fallback mode.");
        }

        $result = \gethostbynamel($name);
        if ($result === false) {
            throw new DnsException("Query for '{$name}' failed, because loading the system's DNS configuration failed and blocking fallback via gethostbynamel() failed, too.");
        }

        if ($result === []) {
            throw new NoRecordException("No records returned for '{$name}' using blocking fallback mode.");
        }

        $records = [];

        foreach ($result as $record) {
            $records[] = new Record($record, Record::A, null);
        }

        return $records;
    }
}
