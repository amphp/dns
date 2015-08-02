<?php

error_reporting(E_ALL);

if (ini_get("opcache.enable") == true &&
    ini_get("opcache.save_comments") == false) {
    echo "Cannot run tests. OPCache is enabled and is stripping comments, which are required by PHPUnit to provide data for the tests.\n";
    exit(-1);
}
