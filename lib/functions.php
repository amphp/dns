<?php

namespace Amp\Dns;

use Amp\Loop;
use Amp\Promise;

const LOOP_STATE_IDENTIFIER = Resolver::class;

/**
 * Retrieve the application-wide dns resolver instance
 *
 * @param \Amp\Dns\Resolver $resolver Optionally specify a new default dns resolver instance
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
 * Create a new dns resolver best-suited for the current environment
 *
 * @return \Amp\Dns\Resolver
 */
function driver(): Resolver {
    return new DefaultResolver;
}

/**
 * Resolve a hostname name to an IP address
 * [hostname as defined by RFC 3986]
 *
 * Upon success the returned promise resolves to an indexed array of the form:
 *
 *  [string $recordValue, int $type, int $ttl]
 *
 * A null $ttl value indicates the DNS name was resolved from the cache or the
 * local hosts file.
 * $type being one constant from Amp\Dns\Record
 *
 * Options:
 *
 *  - "server"       | string   Custom DNS server address in ip or ip:port format (Default: 8.8.8.8:53)
 *  - "timeout"      | int      DNS server query timeout (Default: 3000ms)
 *  - "hosts"        | bool     Use the hosts file (Default: true)
 *  - "reload_hosts" | bool     Reload the hosts file (Default: false), only active when no_hosts not true
 *  - "cache"        | bool     Use local DNS cache when querying (Default: true)
 *  - "types"        | array    Default: [Record::A, Record::AAAA] (only for resolve())
 *  - "recurse"      | bool     Check for DNAME and CNAME records (always active for resolve(), Default: false for query())
 *
 * If the custom per-request "server" option is not present the resolver will
 * use the first nameserver in /etc/resolv.conf or default to Google's public
 * DNS servers on Windows or if /etc/resolv.conf is not found.
 *
 * @param string $name The hostname to resolve
 * @param array  $options
 * @return \Amp\Promise
 * @TODO add boolean "clear_cache" option flag
 */
function resolve(string $name, array $options = []): Promise {
    return resolver()->resolve($name, $options);
}

/**
 * Query specific DNS records.
 *
 * @param string $name Unlike resolve(), query() allows for requesting _any_ name (as DNS RFC allows for arbitrary strings)
 * @param int|int[] $type Use constants of Amp\Dns\Record
 * @param array $options @see resolve documentation
 * @return \Amp\Promise
 */
function query(string $name, $type, array $options = []): Promise {
    return resolver()->query($name, $type, $options);
}

if (\function_exists('idn_to_ascii')) {
    /**
     * Normalizes a DNS name.
     *
     * @param string $label DNS name.
     *
     * @return string Normalized DNS name.
     *
     * @throws \Error If an invalid name or an IDN name without ext/intl being installed has been passed.
     */
    function normalizeName(string $label): string {
        if (false === $result = \idn_to_ascii($label, 0, INTL_IDNA_VARIANT_UTS46)) {
            throw new \Error("Label '{$label}' could not be processed for IDN");
        }

        return $result;
    }
} else {
    function normalizeName(string $label): string {
        if (\preg_match('/[\x80-\xff]/', $label)) {
            throw new \Error(
                "Label '{$label}' contains non-ASCII characters and IDN support is not available."
                . " Verify that ext/intl is installed for IDN support."
            );
        }

        return \strtolower($label);
    }
}
