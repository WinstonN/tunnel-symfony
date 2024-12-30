<?php

namespace App\Service;

use Aws\Ec2\Ec2Client;
use Aws\Ssm\SsmClient;

class AWSClient
{
    private Ec2Client $ec2Client;
    private SsmClient $ssmClient;

    public function __construct()
    {
        $region = getenv('AWS_REGION') ?: getenv('AWS_DEFAULT_REGION') ?: 'eu-central-1';
        $this->log("Using AWS region: " . $region);

        $config = [
            'version' => 'latest',
            'region'  => $region,
            'endpoint_discovery_enabled' => true
        ];

        if (getenv('AWS_PROFILE')) {
            $this->log("Using AWS profile: " . getenv('AWS_PROFILE'));
        }

        $this->ec2Client = new Ec2Client($config);
        $this->ssmClient = new SsmClient($config);
    }

    public function getJumphost(string $environment, string $filter): ?array
    {
        $result = $this->ec2Client->describeInstances([
            'Filters' => [
                [
                    'Name' => 'tag:Name',
                    'Values' => [$filter]
                ],
                [
                    'Name' => 'instance-state-name',
                    'Values' => ['running']
                ]
            ]
        ]);

        foreach ($result['Reservations'] as $reservation) {
            foreach ($reservation['Instances'] as $instance) {
                return [
                    'InstanceId' => $instance['InstanceId'],
                    'Name' => $this->getInstanceName($instance),
                    'PrivateIpAddress' => $instance['PrivateIpAddress']
                ];
            }
        }

        return null;
    }

    public function getParameter(string $name): string
    {
        $result = $this->ssmClient->getParameter([
            'Name' => $name,
            'WithDecryption' => true
        ]);

        return $result['Parameter']['Value'];
    }

    public function getParametersByPath(array $paths): array
    {
        $parameters = [];
        foreach ($paths as $path) {
            try {
                $result = $this->ssmClient->getParametersByPath([
                    'Path' => $path,
                    'Recursive' => true,
                    'WithDecryption' => true
                ]);

                foreach ($result['Parameters'] as $parameter) {
                    $parameters[] = $parameter;
                }
            } catch (\Exception $e) {
                // Log error but continue with other paths
                error_log("Error getting parameters for path $path: " . $e->getMessage());
            }
        }

        return $parameters;
    }

    private function getInstanceName(array $instance): string
    {
        foreach ($instance['Tags'] as $tag) {
            if ($tag['Key'] === 'Name') {
                return $tag['Value'];
            }
        }
        return $instance['InstanceId'];
    }

    private function log(string $message): void 
    {
        echo "[AWSClient] " . $message . PHP_EOL;
    }
}
