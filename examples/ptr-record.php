<?php

require __DIR__ . "/_bootstrap.php";

use Amp\Dns;
use Amp\Loop;

Loop::run(function () {
    $ip = "8.8.8.8";

    try {
        pretty_print_records($ip, yield Dns\query($ip, Dns\Record::PTR));
    } catch (Dns\ResolutionException $e) {
        pretty_print_error($ip, $e);
    }
});
