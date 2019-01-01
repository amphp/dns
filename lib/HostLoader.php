<?php

namespace Amp\Dns;

use Amp\Promise;

interface HostLoader {
    /**
     * Loads an array of DNS records defined by the system hosts file.
     * The first array index should be the record type (e.g., Record::AAAA) and the second index the host name.
     *
     * @return Promise<string[][]>
     */
    public function loadHosts(): Promise;
}
