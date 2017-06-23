<?php

require __DIR__ . "/../vendor/autoload.php";

use Amp\Dns;
use Amp\Loop;

$domains = file_get_contents("https://moz.com/top500/domains/csv");
$domains = array_map(function ($line) {
    return trim(explode(",", $line)[1], '"/');
}, array_filter(explode("\n", $domains)));

Loop::run(function () use ($domains) {
    for ($i = 0; $i < 10; $i++) {
        $domain = $domains[mt_rand(0, count($domains) - 1)];

        try {
            yield Dns\resolve($domain);
        } catch (Dns\ResolutionException $e) {
            print $domain . ": " . get_class($e) . PHP_EOL;
        }
    }
});
