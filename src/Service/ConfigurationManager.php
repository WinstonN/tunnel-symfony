<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class ConfigurationManager
{
    private array $config;

    public function __construct(?string $configFile = null)
    {
        $this->loadConfig($configFile);
    }

    public function loadConfig(?string $configFile): void
    {
        $locations = [
            $configFile,
            'config.yaml',
            'tunnel-symfony.yaml',
            $_SERVER['HOME'] . '/.tunnel-symfony/config.yaml',
            $_SERVER['HOME'] . '/.config/tunnel-symfony.yaml',
        ];

        foreach ($locations as $location) {
            if ($location && file_exists($location)) {
                $this->config = Yaml::parseFile($location);
                return;
            }
        }

        throw new \RuntimeException('No configuration file found');
    }

    public function getServiceConfig(string $serviceName): array
    {
        if (!isset($this->config['tunnel-symfony-config']['services'][$serviceName])) {
            throw new \RuntimeException("Service '$serviceName' not found in configuration");
        }

        $serviceConfig = $this->config['tunnel-symfony-config']['services'][$serviceName];
        $this->validateServiceConfig($serviceConfig);

        return $serviceConfig;
    }

    public function getJumphostFilter(string $environment): string
    {
        $filter = $this->config['tunnel-symfony-config']['jumphost-filter'];
        return str_replace('${PLACEHOLDER}', $environment, $filter);
    }

    private function validateServiceConfig(array $config): void
    {
        // Validate host configuration
        if (!isset($config['host'])) {
            throw new \RuntimeException('Service configuration must include host');
        }
        $this->validateConfigValue($config['host']);

        // Validate remote port configuration
        if (!isset($config['remote-port'])) {
            throw new \RuntimeException('Service configuration must include remote-port');
        }
        $this->validateConfigValue($config['remote-port']);

        // Validate local port range
        if (!isset($config['local-port-range'])) {
            throw new \RuntimeException('Service configuration must include local-port-range with start and end');
        }
        
        if (!isset($config['local-port-range']['start']) ||
            !isset($config['local-port-range']['end'])) {
            throw new \RuntimeException('Service configuration must include local-port-range with start and end');
        }
    }

    private function validateConfigValue(array $config): void
    {
        if (!isset($config['value']) && !isset($config['ssm-param'])) {
            throw new \RuntimeException('Configuration must specify either value or ssm-param');
        }

        // If both are set, use ssm-param if value is empty, use value if ssm-param is empty
        if (isset($config['value']) && isset($config['ssm-param'])) {
            if (!empty($config['value']) && !empty($config['ssm-param'])) {
                throw new \RuntimeException('Configuration cannot specify both non-empty value and ssm-param');
            }
        }
    }
}
