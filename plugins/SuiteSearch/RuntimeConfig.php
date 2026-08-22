<?php

namespace TypechoPlugin\SuiteSearch;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class RuntimeConfig
{
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromFile(string $path): self
    {
        $values = @parse_ini_file($path, false, INI_SCANNER_RAW);
        if (!is_array($values)) {
            throw new \RuntimeException('Unable to read search configuration: ' . $path);
        }

        return new self($values);
    }

    public function require(string $name): string
    {
        $value = trim((string) ($this->values[$name] ?? ''));
        if ($value === '') {
            throw new \RuntimeException('Missing search configuration: ' . $name);
        }

        return $value;
    }

    public function get(string $name, string $default = ''): string
    {
        return trim((string) ($this->values[$name] ?? $default));
    }

    public function getInt(string $name, int $default): int
    {
        $value = $this->get($name);
        return $value !== '' && ctype_digit($value) ? (int) $value : $default;
    }
}
