<?php

namespace Amp\Dns;

use Amp\Loop;
use Amp\Promise;

const LOOP_STATE_IDENTIFIER = Resolver::class;

/**
 * Retrieve the application-wide dns resolver instance.
 *
 * @param \Amp\Dns\Resolver $resolver Optionally specify a new default dns resolver instance
 *
 * @return \Amp\Dns\Resolver Returns the application-wide dns resolver instance
 */
function resolver(Resolver $resolver = null): Resolver {
    if ($resolver === null) {
        $resolver = Loop::getState(LOOP_STATE_IDENTIFIER);

        if ($resolver) {
            return $resolver;
        }

        $resolver = driver();
    }

    Loop::setState(LOOP_STATE_IDENTIFIER, $resolver);

    return $resolver;
}

/**
 * Create a new dns resolver best-suited for the current environment.
 *
 * @return \Amp\Dns\Resolver
 */
function driver(): Resolver {
    return new BasicResolver;
}

/**
 * @see Resolver::resolve()
 */
function resolve(string $name, int $typeRestriction = null): Promise {
    return resolver()->resolve($name, $typeRestriction);
}

/**
 * @see Resolver::query()
 */
function query(string $name, int $type): Promise {
    return resolver()->query($name, $type);
}
