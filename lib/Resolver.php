<?php declare(strict_types = 1);

namespace Amp\Dns;

interface Resolver {
    /**
     * @see \Amp\Dns\resolve
     */
    public function resolve(string $name, array $options = []);

    /**
     * @see \Amp\Dns\query
     */
    public function query(string $name, $type, array $options = []);
}