<?php declare(strict_types=1);

namespace Amp\Dns;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Process\Process;
use function Amp\ByteStream\buffer;

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
        $wmic = Process::start(['wmic', 'NICCONFIG', 'GET', 'DNSServerSearchOrder']);

        if ($wmic->join() !== 0) {
            throw new DnsConfigException("Could not fetch DNS servers from WMI: " . buffer($wmic->getStderr()));
        }

        $ips = self::parseWmicOutput(buffer($wmic->getStdout()));

        $nameservers = \array_reduce($ips, static function (array $nameservers, string $address): array {
            $ip = \inet_pton($address);

            if (isset($ip[15])) { // IPv6
                $nameservers[] = "[$address]:53";
            } elseif (isset($ip[3])) { // IPv4
                $nameservers[] = "$address:53";
            }

            return $nameservers;
        }, []);

        $hosts = $this->hostLoader->loadHosts();

        return new DnsConfig($nameservers, $hosts);
    }

    private static function parseWmicOutput(string $output): array
    {
        // Massage WMIC output into JSON format.
        $json = \preg_replace(
            [
                // Convert header line into opening bracket.
                '[^\V*\v+]',
                // Convert closing braces into commas.
                '[}]',
                // Remove final comma.
                '[,(?=[^,]*+$)]',
                // Removing opening braces.
                '[{]',
            ],
            [
                '[',
                ',',
            ],
            $output,
        );

        return \json_decode("$json]", true, flags: \JSON_THROW_ON_ERROR);
    }
}
