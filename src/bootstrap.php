<?php

spl_autoload_register(function($className) {
    if (strpos($className, 'Addr\\') === 0) {
        require dirname(__DIR__) . '/lib/' . strtr($className, '\\', '/') . '.php';
    }
});
