<?php

require __DIR__ . "/_bootstrap.php";

use Amp\Dns;
use Amp\Loop;
use Amp\Promise;

$customConfigLoader = new class implements Dns\ConfigLoader {
    public function loadConfig(): Promise
    {
        return Amp\call(function () {
            $hosts = yield (new Dns\HostLoader)->loadHosts();

            return new Dns\Config([
                "8.8.8.8:53",
                "[2001:4860:4860::8888]:53",
            ], $hosts, $timeout = 5000, $attempts = 3);
        });
    }
};

Dns\resolver(new Dns\Rfc1035StubResolver(null, $customConfigLoader));

Loop::run(function () {
    $hostname = "amphp.org";

    try {
        pretty_print_records($hostname, yield Dns\resolve($hostname));
    } catch (Dns\DnsException $e) {
        pretty_print_error($hostname, $e);
    }
});
