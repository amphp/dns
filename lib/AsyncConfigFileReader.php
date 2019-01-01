<?php

namespace Amp\Dns;

use Amp\File;
use Amp\Promise;
use function Amp\call;

class AsyncConfigFileReader implements ConfigFileReader {
    public function read(string $path): Promise {
        return call(function () use ($path) {
            try {
                return yield File\get($path);
            } catch (File\FilesystemException $e) {
                throw new ConfigException("Could not read configuration file '{$path}'", $e);
            }
        });
    }
}
