<?php

namespace Amp\Dns;

use Amp\Promise;

interface Resolver {
    /**
     * @see \Amp\Dns\resolve
     */
    public function resolve(string $name): Promise;

    /**
     * @see \Amp\Dns\query
     */
    public function query(string $name, $type): Promise;
}
