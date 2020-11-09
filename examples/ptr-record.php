<?php

require __DIR__ . "/_bootstrap.php";

use Amp\Dns;

$ip = $argv[1] ?? "8.8.8.8";

try {
    pretty_print_records($ip, Dns\query($ip, Dns\Record::PTR));
} catch (Dns\DnsException $e) {
    pretty_print_error($ip, $e);
}
