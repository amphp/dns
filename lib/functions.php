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
 * Resolve a hostname name to an IP address [hostname as defined by RFC 3986].
 *
 * Upon success the returned promise resolves to an array of Record objects.
 *
 * A null $ttl value indicates the DNS name was resolved from the cache or the local hosts file.
 *
 * @param string $name The hostname to resolve
 *
 * @return Promise
 */
function resolve(string $name): Promise {
    return resolver()->resolve($name);
}

/**
 * Query specific DNS records.
 *
 * @param string    $name Unlike resolve(), query() allows for requesting _any_ name (as DNS RFC allows for arbitrary
 *     strings)
 * @param int|int[] $type Use constants of Amp\Dns\Record
 *
 * @return Promise
 */
function query(string $name, $type): Promise {
    return resolver()->query($name, $type);
}

/**
 * Checks whether a string is a valid DNS name.
 *
 * @param string $name String to check.
 *
 * @return bool
 */
function isValidDnsName(string $name) {
    try {
        normalizeDnsName($name);
        return true;
    } catch (InvalidNameError $e) {
        return false;
    }
}

/**
 * Normalizes a DNS name and automatically checks it for validity.
 *
 * @param string $name DNS name.
 *
 * @return string Normalized DNS name.
 *
 * @throws InvalidNameError If an invalid name or an IDN name without ext/intl being installed has been passed.
 */
function normalizeDnsName(string $name): string {
    static $pattern = '/^(?<name>[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.(?&name))*$/i';

    if (\function_exists('idn_to_ascii')) {
        if (false === $result = \idn_to_ascii($name, 0, INTL_IDNA_VARIANT_UTS46)) {
            throw new InvalidNameError("Name '{$name}' could not be processed for IDN.");
        }

        $name = $result;
    } else {
        if (\preg_match('/[\x80-\xff]/', $name)) {
            throw new InvalidNameError(
                "Name '{$name}' contains non-ASCII characters and IDN support is not available. " .
                "Verify that ext/intl is installed for IDN support."
            );
        }
    }

    if (isset($name[253]) || !\preg_match($pattern, $name)) {
        throw new InvalidNameError("Name '{$name}' is not a valid hostname.");
    }

    return $name;
}
