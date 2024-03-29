<?php declare(strict_types=1);

require __DIR__ . "/_bootstrap.php";

use Amp\Dns;

print "Downloading top 500 domains..." . PHP_EOL;

$domains = file_get_contents("https://moz.com/top-500/download?table=top500Domains");
$domains = array_map(function ($line) {
    return trim(explode(",", $line)[1], '"/');
}, array_filter(explode("\n", $domains)));

// Remove "URL" header
array_shift($domains);

print "Starting sequential queries...\r\n\r\n";

$timings = [];

for ($i = 0; $i < 10; $i++) {
    $start = microtime(1);
    $domain = $domains[random_int(0, count($domains) - 1)];

    try {
        pretty_print_records($domain, Dns\resolve($domain));
    } catch (Dns\DnsException $e) {
        pretty_print_error($domain, $e);
    }

    $time = round(microtime(1) - $start, 2);
    $timings[] = $time;

    printf("%'-74s\r\n\r\n", " in " . $time . " ms");
}

$averageTime = array_sum($timings) / count($timings);

print "{$averageTime} ms for an average query." . PHP_EOL;
