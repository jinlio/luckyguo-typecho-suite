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

    /**
     * Read settings saved by the Typecho plugin configuration page.
     * The option names intentionally stay separate from the legacy env names.
     */
    public static function fromOptions(object $options): self
    {
        $settings = $options->plugin('SuiteSearch');
        $fields = [
            'enabled' => 'ENABLED',
            'meiliUrl' => 'MEILI_URL',
            'searchKey' => 'SEARCH_KEY',
            'writeKey' => 'WRITE_KEY',
            'rebuildKey' => 'REBUILD_KEY',
            'taskKey' => 'TASK_KEY',
            'liveIndex' => 'MEILI_INDEX_LIVE',
            'buildIndex' => 'MEILI_INDEX_BUILD',
            'rebuildFenceTimeout' => 'REBUILD_FENCE_TIMEOUT',
            'autoSync' => 'AUTO_SYNC',
            'mysqlFallback' => 'MYSQL_FALLBACK',
        ];
        $values = [];
        $hasSettings = false;
        foreach ($fields as $field => $name) {
            $value = $settings->$field ?? null;
            if ($value !== null) {
                $hasSettings = true;
                if (is_array($value)) {
                    $value = in_array('1', array_map('strval', $value), true) ? '1' : '0';
                }
                $values[$name] = (string) $value;
            }
        }
        if (!$hasSettings) {
            throw new \RuntimeException('SuiteSearch backend settings are not saved');
        }
        if (!array_key_exists('ENABLED', $values)) {
            $values['ENABLED'] = '0';
        }
        return new self($values);
    }

    public static function fromOptionsOrFile(object $options, string $path): self
    {
        try {
            $configured = self::fromOptions($options);
            // open_basedir can reject an optional legacy path; treat that as absent.
            if (@is_readable($path)) {
                $legacy = self::fromFile($path);
                return $configured->withFallback($legacy);
            }
            return $configured;
        } catch (\Throwable $error) {
            return self::fromFile($path);
        }
    }

    public function withFallback(self $fallback): self
    {
        $values = $this->values;
        foreach ($fallback->values as $name => $value) {
            if (!isset($values[$name]) || trim((string) $values[$name]) === '') {
                $values[$name] = $value;
            }
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

    public function getBool(string $name, bool $default): bool
    {
        $value = strtolower($this->get($name));
        if ($value === '') {
            return $default;
        }
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
