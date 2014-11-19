<?php

use Auryn\Provider;

error_reporting(E_ALL);

if (ini_get('opcache.enable') == true &&
    ini_get('opcache.save_comments') == false) {
    echo "Cannot run tests. OPCache is enabled and is stripping comments, which are required by PHPUnit to provide data for the tests.\n";
    exit(-1);
}

function createProvider($implementations = [], $shareClasses = []) {
    $provider = new Provider();

    $standardImplementations = [
    ];

    $standardShares = [
    ];

    $redisParameters = [
        'connection_timeout' => 2,
        'read_write_timeout' => 2,
    ];

    $provider->define(
        'Predis\Client',
        [
            ':parameters' => $redisParameters,
            ':options' => [],
        ]
    );

    $provider->share('Predis\Client');

    setImplementations($provider, $standardImplementations, $implementations);
    setShares($provider, $standardShares, $shareClasses);
    $provider->share($provider); //YOLO

    return $provider;
}

function setImplementations(Provider $provider, $standardImplementations, $implementations) {
    foreach ($standardImplementations as $interface => $implementation) {
        if (array_key_exists($interface, $implementations)) {
            if (is_object($implementations[$interface]) == true) {
                $provider->alias($interface, get_class($implementations[$interface]));
                $provider->share($implementations[$interface]);
            }
            else {
                $provider->alias($interface, $implementations[$interface]);
            }
            unset($implementations[$interface]);
        }
        else {
            if (is_object($implementation)) {
                $implementation = get_class($implementation);
            }
            $provider->alias($interface, $implementation);
        }
    }

    foreach ($implementations as $class => $implementation) {
        if (is_object($implementation) == true) {
            $provider->alias($class, get_class($implementation));
            $provider->share($implementation);
        }
        else {
            $provider->alias($class, $implementation);
        }
    }
}

function setShares(Provider $provider, $standardShares, $shareClasses) {
    foreach ($standardShares as $class => $share) {
        if (array_key_exists($class, $shareClasses)) {
            $provider->share($shareClasses[$class]);
            unset($shareClasses[$class]);
        }
        else {
            $provider->share($share);
        }
    }

    foreach ($shareClasses as $class => $share) {
        $provider->share($share);
    }
}
