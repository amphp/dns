dns
===

Asynchronous DNS resolution built on the [Amp](https://github.com/amphp/amp) concurrency framework


## Examples

**Synchronous Resolution Via `wait()`**

```php
<?php
require __DIR__ . '/vendor/autoload.php';

$resolver = new Amp\Dns\Resolver;
$name = 'google.com';
list($address, $type) = $resolver->resolve($name)->wait();
printf("%s resolved to %s\n", $name, $address);
```

**Concurrent Synchronous Resolution Via `wait()`**

```php
<?php
require __DIR__ . '/vendor/autoload.php';

$resolver = new Amp\Dns\Resolver;
$names = [
    'github.com',
    'google.com',
    'stackoverflow.com',
];
$promises = [];
foreach ($names as $name) {
    $promises[$name] = $resolver->resolve($name);
}
$results = Amp\all($promises)->wait();
foreach ($results as $name => $resultArray) {
    list($addr, $type) = $resultArray;
    printf("%s => %s\n", $name, $addr);
}
```


**Event Loop Async**

```php
<?php
require __DIR__ . '/vendor/autoload.php';

Amp\run(function() {
    $resolver = new Amp\Dns\Resolver;
    $names = [
        'github.com',
        'google.com',
        'stackoverflow.com',
        'localhost',
        '192.168.0.1',
        '::1',
    ];

    $promises = [];
    foreach ($names as $name) {
        $promise = $resolver->resolve($name);
        $promises[$name] = $promise;
    }

    // Flatten multiple promises into a single promise
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
