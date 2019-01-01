<?php

namespace Amp\Dns;

use Amp\Promise;

interface ConfigFileReader {
    /**
     * @param string $path
     *
     * @return Promise<string> File contents.
     *
     * @throws ConfigException If loading the config file fails.
     */
    public function read(string $path): Promise;
}
