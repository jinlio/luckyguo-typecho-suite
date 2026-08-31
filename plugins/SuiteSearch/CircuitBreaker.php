<?php

namespace TypechoPlugin\SuiteSearch;

/** Small file-backed breaker so separate PHP requests share the cooldown. */
final class CircuitBreaker
{
    /** @var callable():int */
    private $clock;
    private string $path;
    private int $cooldown;
    private int $failureUntil = 0;

    /** @param callable():int|null $clock */
    public function __construct(string $path, int $cooldown = 30, ?callable $clock = null)
    {
        $this->path = $path;
        $this->cooldown = max(1, $cooldown);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function isOpen(): bool
    {
        $now = ($this->clock)();
        if ($this->failureUntil > $now) {
            return true;
        }
        $raw = @file_get_contents($this->path);
        $until = is_string($raw) && ctype_digit(trim($raw)) ? (int) trim($raw) : 0;
        $this->failureUntil = max($this->failureUntil, $until);
        return $this->failureUntil > $now;
    }

    public function trip(): void
    {
        $until = ($this->clock)() + $this->cooldown;
        $this->failureUntil = max($this->failureUntil, $until);
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }
        $temporary = $this->path . '.tmp.' . getmypid();
        if (@file_put_contents($temporary, (string) $this->failureUntil, LOCK_EX) !== false) {
            @chmod($temporary, 0640);
            @rename($temporary, $this->path);
        }
        @unlink($temporary);
    }

    public function clear(): void
    {
        $now = ($this->clock)();
        $this->failureUntil = 0;
        $raw = @file_get_contents($this->path);
        if (is_string($raw) && ctype_digit(trim($raw)) && (int) trim($raw) <= $now) {
            @unlink($this->path);
        }
    }
}
