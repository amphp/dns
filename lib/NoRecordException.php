<?php

namespace Amp\Dns;

use Throwable;

class NoRecordException extends ResolutionException {
    private $recursive;

    public function __construct(string $message, bool $recursive, Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->recursive = $recursive;
    }

    public function wasRecursiveQuery() {
        return $this->recursive;
    }
}
