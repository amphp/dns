<?php

namespace Amp\Dns;

interface Resolver {
    /**
     * @see \Amp\Dns\resolve
     */
    public function resolve($name, array $options = []);

    /**
     * @see \Amp\Dns\query
     */
    public function query($name, $type, array $options = []);
}