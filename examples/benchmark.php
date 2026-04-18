<?php declare(strict_types=1);

require __DIR__ . "/_bootstrap.php";

use Amp\Dns;

print "Downloading top 500 domains..." . PHP_EOL;

$domains = file_get_contents("https://moz.com/top-500/download?table=top500Domains");
if ($domains === false) {
    throw new \RuntimeException("Failed to download top 500 domains");
}

$domains = array_map(
    fn (string $line) => trim(explode(",", $line)[1], '"/'),
    array_filter(explode("\n", $domains)),
);

// Remove "URL" header
array_shift($domains);

print "Starting sequential queries..." . PHP_EOL . PHP_EOL;

$timings = [];

for ($i = 0; $i < 10; $i++) {
    $start = microtime(true);
    $domain = $domains[random_int(0, count($domains) - 1)];

    try {
        pretty_print_records($domain, Dns\resolve($domain));
    } catch (Dns\DnsException $e) {
        pretty_print_error($domain, $e);
    }

    $time = microtime(true) - $start;
    $timings[] = $time;

    printf("in %.5f ms" . PHP_EOL . PHP_EOL, $time);
}

printf("%.5f ms for an average query.", array_sum($timings) / (float) count($timings)) . PHP_EOL;
