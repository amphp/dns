<?php

require __DIR__ . '/../vendor/autoload.php';

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
        echo isset($errors[$name]) ? "FAILED: {$name}\n" : "{$name} => {$successes[$name][0]}\n";
    }

    // Stop the event loop so we don't sit around forever
    Amp\stop();
});
