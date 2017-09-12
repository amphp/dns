<?php

require __DIR__ . "/../vendor/autoload.php";

use Amp\Dns;
use Amp\Loop;
use Amp\Promise;

$customConfigLoader = new class implements Dns\ConfigLoader {
    public function loadConfig(): Promise {
        return Amp\call(function () {
            $hosts = yield (new Dns\HostLoader)->loadHosts();

            return new Dns\Config([
                "8.8.8.8:53",
                "[2001:4860:4860::8888]:53",
            ], $hosts, $timeout = 5000, $attempts = 3);
        });
    }
};

Dns\resolver(new Dns\BasicResolver(null, $customConfigLoader));

Loop::run(function () {
    var_dump(yield Dns\resolve("google.com"));
});
