<?php

declare(strict_types=1);

namespace Diffalyzer\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads and parses configuration from YAML files
 */
class ConfigLoader
{
    private const DEFAULT_CONFIG_FILES = [
        '.diffalyzer.yml',
        'diffalyzer.yml',
        'config.yml',
    ];

    private const DEFAULT_PATTERNS = [
        'composer.json',
        'composer.lock',
    ];

    /**
     * Load configuration from file
     *
     * @param string|null $configPath Custom config file path, or null to auto-detect
     * @return array{full_scan_patterns: array<string>|null}
     */
    public function load(?string $configPath = null): array
    {
        $config = $this->loadConfigFile($configPath);

        // If no config file was found, return null for patterns (will use built-in defaults)
        // If config file exists but doesn't define patterns, return null (will use built-in defaults)
        // If config file explicitly sets patterns (even empty array), return that value
        $patterns = null;
        if ($config !== null) {
            $patterns = $config['full_scan_patterns'] ?? null;
        }

        return [
            'full_scan_patterns' => $patterns,
        ];
    }

    /**
     * Load and parse config file
     *
     * @param string|null $configPath
     * @return array<string, mixed>|null Returns null if no config file found
     */
    private function loadConfigFile(?string $configPath): ?array
    {
        if ($configPath !== null) {
            if (!file_exists($configPath)) {
                throw new \RuntimeException("Config file not found: {$configPath}");
            }
            return $this->parseYamlFile($configPath);
        }

        // Auto-detect config file
        foreach (self::DEFAULT_CONFIG_FILES as $filename) {
            if (file_exists($filename)) {
                return $this->parseYamlFile($filename);
            }
        }

        // No config file found, return null
        return null;
    }

    /**
     * Parse YAML file
     *
     * @param string $filepath
     * @return array<string, mixed>
     */
    private function parseYamlFile(string $filepath): array
    {
        if (!class_exists(Yaml::class)) {
            throw new \RuntimeException(
                'Symfony YAML component is required to parse config files. ' .
                'Install it with: composer require symfony/yaml'
            );
        }

        $contents = file_get_contents($filepath);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read config file: {$filepath}");
        }

        $parsed = Yaml::parse($contents);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Get default full-scan patterns (used as fallback when no config provided)
     *
     * @return array<string>
     */
    public function getDefaultPatterns(): array
    {
        return self::DEFAULT_PATTERNS;
    }
}
