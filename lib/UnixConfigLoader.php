<?php

namespace Amp\Dns;

use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use function Amp\call;

class UnixConfigLoader implements ConfigLoader
{
    const MAX_NAMESERVERS = 3;
    const MAX_DNS_SEARCH = 6;

    const MAX_TIMEOUT = 30 * 1000;
    const MAX_ATTEMPTS = 5;
    const MAX_NDOTS = 15;

    const DEFAULT_TIMEOUT = 5 * 1000;
    const DEFAULT_ATTEMPTS = 2;
    const DEFAULT_NDOTS = 1;

    const DEFAULT_OPTIONS = [
        "timeout" => self::DEFAULT_TIMEOUT,
        "attempts" => self::DEFAULT_ATTEMPTS,
        "ndots" => self::DEFAULT_NDOTS,
        "rotate" => false,
    ];
    private $path;
    private $hostLoader;

    public function __construct(string $path = "/etc/resolv.conf", HostLoader $hostLoader = null)
    {
        $this->path = $path;
        $this->hostLoader = $hostLoader ?? new HostLoader;
    }

    protected function readFile(string $path): Promise
    {
        \set_error_handler(function (int $errno, string $message) use ($path) {
            throw new ConfigException("Could not read configuration file '{$path}' ({$errno}) $message");
        });

        try {
            // Blocking file access, but this file should be local and usually loaded only once.
            $fileContent = \file_get_contents($path);
        } catch (ConfigException $exception) {
            return new Failure($exception);
        } finally {
            \restore_error_handler();
        }

        return new Success($fileContent);
    }

    final public function loadConfig(): Promise
    {
        return call(function () {
            $nameservers = [];
            $searchList = [];
            $options = self::DEFAULT_OPTIONS;
            $haveLocaldomainEnv = false;

            /* Allow user to override the local domain definition.  */
            if ($localdomain = \getenv("LOCALDOMAIN")) {
                /* Set search list to be blank-separated strings from rest of
                   env value.  Permits users of LOCALDOMAIN to still have a
                   search list, and anyone to set the one that they want to use
                   as an individual (even more important now that the rfc1535
                   stuff restricts searches).  */
                $searchList = $this->splitOnWhitespace($localdomain);
                $haveLocaldomainEnv = true;
            }

            $fileContent = yield $this->readFile($this->path);

            $lines = \explode("\n", $fileContent);

            foreach ($lines as $line) {
                $line = \preg_split('#\s+#', $line, 2);

                if (\count($line) !== 2) {
                    continue;
                }

                list($type, $value) = $line;

                if ($type === "nameserver") {
                    if (\count($nameservers) === self::MAX_NAMESERVERS) {
                        continue;
                    }
                    $value = \trim($value);
                    $ip = @\inet_pton($value);

                    if ($ip === false) {
                        continue;
                    }

                    if (isset($ip[15])) { // IPv6
                        $nameservers[] = "[" . $value . "]:53";
                    } else { // IPv4
                        $nameservers[] = $value . ":53";
                    }
                } elseif ($type === "domain" && !$haveLocaldomainEnv) { // LOCALDOMAIN env overrides config
                    $searchList = $this->splitOnWhitespace($value);
                } elseif ($type === "search" && !$haveLocaldomainEnv) { // LOCALDOMAIN env overrides config
                    $searchList = $this->splitOnWhitespace($value);
                } elseif ($type === "options") {
                    $option = $this->parseOption($value);
                    if (\count($option) === 2) {
                        $options[$option[0]] = $option[1];
                    }
                }
            }

            $hosts = yield $this->hostLoader->loadHosts();

            if (\count($searchList) === 0) {
                $hostname = \gethostname();
                $dot = \strpos(".", $hostname);
                if ($dot !== false && $dot < \strlen($hostname)) {
                    $searchList = [
                        \substr($hostname, $dot),
                    ];
                }
            }
            if (\count($searchList) > self::MAX_DNS_SEARCH) {
                $searchList = \array_slice($searchList, 0, self::MAX_DNS_SEARCH);
            }

            $resOptions = \getenv("RES_OPTIONS");
            if ($resOptions) {
                foreach ($this->splitOnWhitespace($resOptions) as $option) {
                    $option = $this->parseOption($option);
                    if (\count($option) === 2) {
                        $options[$option[0]] = $option[1];
                    }
                }
            }

            $config = new Config($nameservers, $hosts, $options["timeout"], $options["attempts"]);

            return $config->withSearchList($searchList)
                ->withNdots($options["ndots"])
                ->withRotationEnabled($options["rotate"]);
        });
    }

    private function splitOnWhitespace(string $names): array
    {
        return \preg_split("#\s+#", \trim($names));
    }

    private function parseOption(string $option): array
    {
        $optline = \explode(':', $option, 2);
        list($name, $value) = $optline + [1 => null];

        switch ($name) {
            case "timeout":
                $value = (int) $value;
                if ($value < 0) {
                    return []; // don't overwrite option value
                }
                // The value for this option is silently capped to 30s
                return ["timeout", (int) \min($value * 1000, self::MAX_TIMEOUT)];

            case "attempts":
                $value = (int) $value;
                if ($value < 0) {
                    return []; // don't overwrite option value
                }
                // The value for this option is silently capped to 5
                return ["attempts", (int) \min($value, self::MAX_ATTEMPTS)];

            case "ndots":
                $value = (int) $value;
                if ($value < 0) {
                    return []; // don't overwrite option value
                }
                // The value for this option is silently capped to 15
                return ["ndots", (int) \min($value, self::MAX_NDOTS)];

            case "rotate":
                return ["rotate", true];
        }

        return [];
    }
}
