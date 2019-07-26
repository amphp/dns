<?php

namespace Amp\Dns;

use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use function Amp\call;

class UnixConfigLoader implements ConfigLoader
{
    const MAXNS = 3;
    const MAXDNSRCH = 6;
    const RES_MAXNDOTS = 15;
    const RES_MAXRETRANS = 30 * 1000;
    const RES_MAXRETRY = 5;
    const RES_TIMEOUT = 5 * 1000;
    const RES_DFLRETRY = 2;
    const DEFAULT_OPTIONS = [
        "timeout" => self::RES_TIMEOUT,
        "attempts" => self::RES_DFLRETRY,
        "ndots" => 1,
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
                $searchList = $this->splitNames($localdomain);
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
                    if (\count($nameservers) === self::MAXNS) {
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
                    $searchList = $this->splitNames($value);
                } elseif ($type === "search" && !$haveLocaldomainEnv) { // LOCALDOMAIN env overrides config
                    $searchList = $this->splitNames($value);
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
                    $searchList = \substr($hostname, $dot);
                }
            }
            if (\count($searchList) > self::MAXDNSRCH) {
                $searchList = \array_slice($searchList, 0, self::MAXDNSRCH);
            }

            $resOptions = \getenv("RES_OPTIONS");
            if ($resOptions) {
                foreach ($this->splitNames($resOptions) as $option) {
                    $option = $this->parseOption($option);
                    if (\count($option) === 2) {
                        $options[$option[0]] = $option[1];
                    }
                }
            }

            return new Config(
                $nameservers,
                $hosts,
                $options["timeout"],
                $options["attempts"],
                $searchList,
                $options["ndots"],
                $options["rotate"]
            );
        });
    }

    private function splitNames(string $names) : array
    {
        return \preg_split("#\s+#", \trim($names));
    }

    private function parseOption(string $option): array
    {
        $optline = \preg_split('#:#', $option, 2);
        list($name, $value) = $optline + [1 => null];

        switch ($name) {
            case "timeout":
                // The value for this option is silently capped to 5s
                return ["timeout", (int) \min((int) $value * 1000, self::RES_TIMEOUT)];

            case "attempts":
                // The value for this option is silently capped to 5
                return ["attempts", (int) \min((int) $value, self::RES_MAXRETRY)];

            case "ndots":
                // The value for this option is silently capped to 15
                return ["ndots", (int) \min((int) $value, self::RES_MAXNDOTS)];

            case "rotate":
                return ["rotate", true];
        }

        return [];
    }
}
