<?php

use Addr\ResolverFactory,
    Alert\ReactorFactory;

require dirname(__DIR__) . '/src/bootstrap.php';

$names = [
    'google.com',
    'github.com',
    'stackoverflow.com',
    'localhost',
    '192.168.0.1',
    '::1',
];

$reactor = (new ReactorFactory)->select();
$resolver = (new ResolverFactory)->createResolver($reactor);

foreach ($names as $name) {
    $resolver->resolve($name, function($addr) use($name, $resolver) {
        echo "{$name}: {$addr}\n";
    });
}

$reactor->run();
