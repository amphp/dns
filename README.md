dns
===

Asynchronous DNS resolution built on the [Amp](https://github.com/amphp/amp) concurrency framework


## Examples

**Synchronous Wait**

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$name = 'google.com';
$resolver = new Amp\Dns\Resolver;
$promise = $resolver->resolve($name);
list($address, $type) = $promise->wait();
printf("%s resolved to %s\n", $name, $address);
```

**Parallel Async**

```php
Amp\run(function() {
    $names = [
        'github.com',
        'google.com',
        'stackoverflow.com',
        'localhost',
        '192.168.0.1',
        '::1',
    ];

    $promises = [];
    $resolver = new Amp\Dns\Resolver;
    foreach ($names as $name) {
        $promise = $resolver->resolve($name);
        $promises[$name] = $promise;
    }

    // Combine our multiple promises into a single promise
    $comboPromise = Amp\some($promises);

    // Yield control until the combo promise resolves
    list($errors, $successes) = (yield $comboPromise);

    foreach ($names as $name) {
        echo isset($errors[$name])
            ? "FAILED: {$name}\n"
            : "{$name} => {$successes[$name][0]}\n";
    }

    // Stop the event loop so we don't sit around forever
    Amp\stop();
});
```

## Tests

[![Build Status](https://travis-ci.org/amphp/dns.svg?branch=master)](https://travis-ci.org/amphp/dns)

Tests can be run from the command line using:

`php vendor/bin/phpunit -c phpunit.xml`

or to exlude tests that require a working internet connection:

`php vendor/bin/phpunit -c phpunit.xml --exclude-group internet`
