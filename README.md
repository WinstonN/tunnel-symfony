# Tunnel Manager

A Symfony CLI application for managing AWS SSM port forwarding tunnels.

## Features

- Create and manage SSM port forwarding tunnels
- Support for multiple services and environments
- Configuration via YAML files
- AWS SSM Parameter Store integration
- Automatic local port assignment
- Service details retrieval
- Docker support for consistent environments

## Requirements

- PHP 8.2 or higher
- Composer
- AWS CLI configured with appropriate credentials
- AWS IAM permissions for EC2 and SSM operations
- Docker (optional, recommended)

## Installation

### Using Docker (Recommended)

1. Clone the repository
2. Make the script executable: `chmod +x run-tunnel.sh`
3. Run using the provided script: `./run-tunnel.sh <command> [options]`

The Docker setup includes all necessary dependencies and handles AWS credential passing automatically.

### Manual Installation

1. Clone the repository
2. Run `composer install`
3. Make the CLI executable: `chmod +x bin/tunnel`
4. (Optional) Create a symlink: `ln -s $(pwd)/bin/tunnel /usr/local/bin/tunnel`

## Configuration

The application looks for configuration files in the following locations:

1. Path specified via `--config` option
2. `config.yaml` in the current directory
3. `tunnel-symfony.yaml` in the current directory
4. `~/.tunnel-symfony/config.yaml`
5. `~/.config/tunnel-symfony.yaml`

See `config-example.yaml` for an example configuration file.

### Configuration Format

The configuration uses hyphenated keys for consistency. Example:

```yaml
tunnel-symfony-config:
  jumphost-filter: ${PLACEHOLDER}-ecs-autoscaled
  services:
    database:
      host:
        ssm-param: /${PLACEHOLDER}/services/database/host
      remote-port:
        value: "3306"
      local-port-range:
        start: 13306
        end: 13315
```

### Environment Placeholder

Throughout the configuration file, you can use `${PLACEHOLDER}` in paths and filters. This placeholder will be automatically replaced with the value provided to the `--environment` option when running commands. For example:

- If your SSM parameter path is `/${PLACEHOLDER}/services/database/host`
- And you run the command with `--environment "dev"`
- The actual path used will be `/dev/services/database/host`

This allows you to use the same configuration file for multiple environments by simply changing the `--environment` value when running commands.

## AWS Configuration

Ensure your AWS credentials are properly configured either via:
- Environment variables (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`)
- AWS credentials file (`~/.aws/credentials`)
- AWS profile (set via `AWS_PROFILE` environment variable)
- IAM instance role (if running on EC2)

Required AWS permissions:
```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "ec2:DescribeInstances",
                "ssm:GetParameter",
                "ssm:GetParameters",
                "ssm:GetParametersByPath",
                "ssm:StartSession"
            ],
            "Resource": "*"
        }
    ]
}
```

## Usage

### Using Docker

Create tunnels:
```bash
./run-tunnel.sh create-tunnel -s "database,redis" -e dev
```

Get service details:
```bash
./run-tunnel.sh service-details -s "database,redis" -e dev
```

Force rebuild of Docker image:
```bash
./run-tunnel.sh --rebuild create-tunnel -s "database,redis" -e dev
```

### Manual Usage

Create tunnels:
```bash
tunnel create-tunnel -s "database,redis" -e dev
```

Get service details:
```bash
tunnel service-details -s "database,redis" -e dev
```

### Command Line Options

- `--services`, `-s`: Comma-separated list of services to tunnel (required)
- `--environment`, `-e`: Environment name (required)
- `--config`: Path to configuration file (optional)
- `--verbose`, `-v`: Enable verbose output
- `--rebuild`: (Docker only) Force rebuild of the Docker image

## Development

### Running Tests

```bash
composer test
```

### Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

MIT License
