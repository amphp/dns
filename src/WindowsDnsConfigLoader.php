<?php declare(strict_types=1);

namespace Amp\Dns;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\WindowsRegistry\KeyNotFoundException;
use Amp\WindowsRegistry\WindowsRegistry;

final class WindowsDnsConfigLoader implements DnsConfigLoader
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly HostLoader $hostLoader = new HostLoader(),
    ) {
    }

    public function loadConfig(): DnsConfig
    {
        $keys = [
            "HKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\Tcpip\\Parameters\\NameServer",
            "HKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\Tcpip\\Parameters\\DhcpNameServer",
        ];

        $nameserver = "";

        while ($nameserver === "" && ($key = \array_shift($keys))) {
            try {
                $nameserver = WindowsRegistry::read($key) ?? '';
            } catch (KeyNotFoundException) {
                // retry other possible locations
            }
        }

        if ($nameserver === "") {
            $interfaces = "HKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\Tcpip\\Parameters\\Interfaces";
            $subKeys = WindowsRegistry::listKeys($interfaces);

            foreach ($subKeys as $key) {
                foreach (["NameServer", "DhcpNameServer"] as $property) {
                    try {
                        $nameserver = WindowsRegistry::read("{$key}\\{$property}") ?? '';

                        if ($nameserver !== "") {
                            break 2;
                        }
                    } catch (KeyNotFoundException) {
                        // retry other possible locations
                    }
                }
            }
        }

        if ($nameserver === "") {
            throw new DnsConfigException("Could not find a nameserver in the Windows Registry");
        }

        $nameservers = [];

        // Microsoft documents space as delimiter, AppVeyor uses comma, we just accept both
        foreach (\explode(" ", \strtr($nameserver, ",", " ")) as $nameserver) {
            $nameserver = \trim($nameserver);
            $ip = \inet_pton($nameserver);

            if ($ip === false) {
                continue;
            }

            if (isset($ip[15])) { // IPv6
                $nameservers[] = "[" . $nameserver . "]:53";
            } else { // IPv4
                $nameservers[] = $nameserver . ":53";
            }
        }

        $hosts = $this->hostLoader->loadHosts();

        return new DnsConfig($nameservers, $hosts);
    }
}
