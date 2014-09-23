<?php

require __DIR__ . '/../vendor/autoload.php';

$name = 'google.com';
$resolver = new Amp\Dns\Resolver;
$promise = $resolver->resolve($name);
list($address, $type) = $promise->wait();
printf("%s resolved to %s\n", $name, $address);
