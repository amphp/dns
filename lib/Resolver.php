<?php

namespace Amp\Dns;

use AsyncInterop\Promise;

interface Resolver {
    /**
     * @see \Amp\Dns\resolve
     */
    public function resolve(string $name, array $options = []): Promise;

    /**
     * @see \Amp\Dns\query
     */
    public function query(string $name, $type, array $options = []): Promise;
}