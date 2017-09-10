<?php

require __DIR__ . "/../vendor/autoload.php";

use Amp\Dns;
use Amp\Loop;

print "Downloading top 500 domains..." . PHP_EOL;

$domains = file_get_contents("https://moz.com/top500/domains/csv");
$domains = array_map(function ($line) {
    return trim(explode(",", $line)[1], '"/');
}, array_filter(explode("\n", $domains)));

// Remove "URL" header
array_shift($domains);

Loop::run(function () use ($domains) {
    print "Starting sequential queries..." . PHP_EOL;

    $timings = [];

    for ($i = 0; $i < 10; $i++) {
        $start = microtime(1);
        $domain = $domains[mt_rand(0, count($domains) - 1)];

        print $domain . ": ";

        try {
            $records = yield Dns\resolve($domain);
            $records = array_map(function ($record) {
                return $record->getValue();
            }, $records);

            print implode(", ", $records);
        } catch (Dns\ResolutionException $e) {
            print get_class($e);
        }

        $time = round(microtime(1) - $start, 2);
        $timings[] = $time;

        print " in " . $time . " ms" . PHP_EOL;
    }

    print PHP_EOL;
    print (array_sum($timings) / count($timings)) . " ms for an average query." . PHP_EOL;
});
