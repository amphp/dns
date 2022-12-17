<?php declare(strict_types=1);

namespace Amp\Dns;

interface DnsConfigLoader
{
    public function loadConfig(): DnsConfig;
}
