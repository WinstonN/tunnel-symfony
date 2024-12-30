<?php

namespace App\Service;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class TunnelManager
{
    private array $tunnels = [];
    private ?array $jumphost = null;
    private string $environment;
    private bool $verbose;

    public function __construct(
        private AWSClient $awsClient,
        private ConfigurationManager $configManager
    ) {
        $this->verbose = false;
    }

    public function setEnvironment(string $environment): void
    {
        $this->environment = $environment;
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    public function createTunnels(array $services): void
    {
        // Get jumphost first
        $this->jumphost = $this->getJumphost();
        if (!$this->jumphost) {
            throw new \RuntimeException('Failed to find jumphost instance');
        }

        $this->log(sprintf('Using jumphost: %s (%s)', $this->jumphost['Name'], $this->jumphost['InstanceId']));

        $lastError = null;
        foreach ($services as $serviceName) {
            try {
                $serviceConfig = $this->configManager->getServiceConfig($serviceName);
                $this->createTunnel($serviceName, $serviceConfig);
            } catch (\Exception $e) {
                $this->log(sprintf('Error creating tunnel for %s: %s', $serviceName, $e->getMessage()));
                $lastError = $e;
                continue;
            }
        }

        if ($lastError) {
            throw new \RuntimeException('One or more tunnels failed to create', 0, $lastError);
        }
    }

    public function createTunnel(string $serviceName, array $serviceConfig): void
    {
        $this->log(sprintf('Creating tunnel for service: %s', $serviceName));

        // Get host and port
        $host = $this->resolveConfigValue($serviceConfig['host']);
        $remotePort = $this->resolveConfigValue($serviceConfig['remote-port']);

        // Find available local port
        $localPort = $this->findAvailablePort(
            $serviceConfig['local-port-range']['start'],
            $serviceConfig['local-port-range']['end']
        );

        $command = [
            'aws', 'ssm', 'start-session',
            '--target', $this->jumphost['InstanceId'],
            '--document-name', 'AWS-StartPortForwardingSessionToRemoteHost',
            '--parameters', json_encode([
                'host' => [$host],
                'portNumber' => [(string)$remotePort],
                'localPortNumber' => [(string)$localPort]
            ])
        ];

        $this->log('Running command: ' . implode(' ', $command));

        $process = new Process($command);
        $process->setTimeout(null);
        $process->setTty(true);
        $process->setPty(true);
        
        try {
            $process->start(function ($type, $buffer) {
                $this->log($buffer);
            });
            
            // Wait a bit to ensure the tunnel is established
            sleep(2);
            
            if (!$process->isRunning()) {
                $error = $process->getErrorOutput() ?: 'No error output available';
                $this->log('Process error output: ' . $error);
                throw new \RuntimeException('Failed to start tunnel process: ' . $error);
            }
            
            $this->tunnels[$serviceName] = $process;

            $this->log(sprintf(
                'Created tunnel for %s: localhost:%d -> %s:%s',
                $serviceName,
                $localPort,
                $host,
                $remotePort
            ));
        } catch (\Exception $e) {
            $this->log(sprintf('Error creating tunnel: %s', $e->getMessage()));
            throw $e;
        }
    }

    public function getServiceDetails(string $serviceName): array
    {
        $serviceConfig = $this->configManager->getServiceConfig($serviceName);
        $details = [];

        if (!empty($serviceConfig['service-details'])) {
            foreach ($serviceConfig['service-details'] as $path) {
                $path = str_replace('${PLACEHOLDER}', $this->environment, $path);
                try {
                    $value = $this->awsClient->getParameter($path);
                    $name = basename($path);
                    $details[$name] = $value;
                } catch (\Exception $e) {
                    $this->log(sprintf('Warning: Failed to get parameter %s: %s', $path, $e->getMessage()));
                }
            }
        }

        return $details;
    }

    public function loadConfig(string $configFile): void
    {
        $this->configManager->loadConfig($configFile);
    }

    private function getJumphost(): ?array
    {
        $filter = $this->configManager->getJumphostFilter($this->environment);
        $this->log(sprintf('Looking for jumphost with filter: %s', $filter));
        
        return $this->awsClient->getJumphost($this->environment, $filter);
    }

    private function resolveConfigValue(array $config): string
    {
        if (isset($config['value']) && !empty($config['value'])) {
            $this->log(sprintf('Using direct value: %s', $config['value']));
            return $config['value'];
        }

        if (isset($config['ssm-param']) && !empty($config['ssm-param'])) {
            $param = str_replace('${PLACEHOLDER}', $this->environment, $config['ssm-param']);
            $this->log(sprintf('Fetching SSM parameter: %s', $param));
            try {
                $value = $this->awsClient->getParameter($param);
                $this->log(sprintf('Got SSM value: %s', $value));
                return $value;
            } catch (\Exception $e) {
                $this->log(sprintf('Error fetching SSM parameter %s: %s', $param, $e->getMessage()));
                // If there's a value field, use it as fallback
                if (isset($config['value'])) {
                    $this->log(sprintf('Using fallback value: %s', $config['value']));
                    return $config['value'];
                }
                throw $e;
            }
        }

        throw new \RuntimeException('Configuration must specify either value or ssm-param');
    }

    private function findAvailablePort(int $start, int $end): int
    {
        $this->log(sprintf('Looking for available port in range %d-%d', $start, $end));
        for ($port = $start; $port <= $end; $port++) {
            $this->log(sprintf('Checking port %d...', $port));
            if ($this->isPortAvailable($port)) {
                $this->log(sprintf('Found available port: %d', $port));
                return $port;
            }
            $this->log(sprintf('Port %d is in use', $port));
        }
        throw new \RuntimeException(sprintf('No available ports in range %d-%d', $start, $end));
    }

    private function isPortAvailable(int $port): bool
    {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($sock) {
            fclose($sock);
            return false;
        }
        return true;
    }

    private function log(string $message): void
    {
        if ($this->verbose) {
            echo $message . PHP_EOL;
        }
    }

    public function cleanup(): void
    {
        foreach ($this->tunnels as $serviceName => $process) {
            $this->log(sprintf('Stopping tunnel for %s', $serviceName));
            $process->stop();
        }
    }
}
