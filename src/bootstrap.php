<?php

$libRoot = dirname(__DIR__);

spl_autoload_register(function($className) use($libRoot) {
    if (strpos($className, 'Addr\\') === 0) {
        require $libRoot . '/lib/' . strtr($className, '\\', '/') . '.php';
    }
});

require $libRoot . '/vendor/Alert/src/bootstrap.php';

spl_autoload_register(function($className) use($libRoot) {
    if (strpos($className, 'LibDNS\\') === 0) {
        require $libRoot . '/vendor/LibDNS/src/' . strtr($className, '\\', '/') . '.php';
    }
});
