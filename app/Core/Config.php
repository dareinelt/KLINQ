<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string,mixed> */
    private array $items = [];

    public function __construct(string $configPath)
    {
        foreach (glob(rtrim($configPath, '/') . '/*.php') ?: [] as $file) {
            $this->items[basename($file, '.php')] = require $file;
        }
    }

    /** @param array<string,mixed> $items */
    public static function fromArray(array $items): self
    {
        $config = new self(sys_get_temp_dir() . '/__no_config__');
        $config->items = $items;

        return $config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $current = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
