<?php

namespace Amp\Dns;

class NameValidator {
    /**
     * Regex for validating domain name format
     *
     * @var string
     */
    private $validatePattern = '/^(?:[a-z0-9][a-z0-9\-]{0,61}[a-z0-9])(?:\.[a-z0-9][a-z0-9\-]{0,61}[a-z0-9])*$/i';

    /**
     * Check that a name is valid
     *
     * @param string $name
     * @return bool
     */
    public function validate($name) {
        return strlen($name) <= 253 && preg_match($this->validatePattern, $name);
    }
}
