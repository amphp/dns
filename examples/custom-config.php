<?php declare(strict_types=1);

require __DIR__ . "/_bootstrap.php";

use Amp\Dns;

$customConfigLoader = new class implements Dns\DnsConfigLoader {
    public function loadConfig(): Dns\DnsConfig
    {
        $hosts = (new Dns\HostLoader)->loadHosts();

        return new Dns\DnsConfig([
            "8.8.8.8:53",
            "[2001:4860:4860::8888]:53",
        ], $hosts, 5, 3);
    }
};

Dns\dnsResolver(new Dns\Rfc1035StubDnsResolver(null, $customConfigLoader));

$hostname = $argv[1] ?? "amphp.org";

try {
    pretty_print_records($hostname, Dns\resolve($hostname));
} catch (Dns\DnsException $e) {
    pretty_print_error($hostname, $e);
}
