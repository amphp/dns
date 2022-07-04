<?php

namespace Amp\Dns;

interface DnsConfigLoader
{
    public function loadConfig(): DnsConfig;
}
