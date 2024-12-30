#!/bin/bash

# Function to show usage
show_usage() {
    echo "Usage: $0 <command> [options]"
    echo "Example:"
    echo "  $0 service-details --services \"database,redis\" --environment \"dev\""
    echo "  $0 create-tunnel --services \"database\" --environment \"dev\""
    echo ""
    echo "Options:"
    echo "  --rebuild    Force rebuild of the Docker image"
    echo "  --config     Path to configuration file"
}

# Set default AWS region if not set
if [ -z "$AWS_REGION" ] && [ -z "$AWS_DEFAULT_REGION" ]; then
    export AWS_REGION="eu-central-1"
fi

# Use AWS_REGION as default for AWS_DEFAULT_REGION if not set
if [ -z "$AWS_DEFAULT_REGION" ]; then
    export AWS_DEFAULT_REGION="$AWS_REGION"
fi

echo "Using AWS Region: ${AWS_REGION}"

# Check if rebuild is requested
REBUILD=0
CONFIG_FILE=""
TUNNEL_ARGS=()

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --rebuild)
            REBUILD=1
            shift
            ;;
        --config)
            CONFIG_FILE="$2"
            shift 2
            ;;
        *)
            TUNNEL_ARGS+=("$1")
            shift
            ;;
    esac
done

# Check if command is provided
if [ ${#TUNNEL_ARGS[@]} -eq 0 ]; then
    show_usage
    exit 1
fi

# Find AWS CLI path
AWS_CLI_PATH=$(which aws)
if [ -z "$AWS_CLI_PATH" ]; then
    echo "Error: AWS CLI not found on host system"
    exit 1
fi

# Build the Docker image if it doesn't exist or rebuild is requested
if [ $REBUILD -eq 1 ] || ! docker images | grep -q "tunnel-symfony"; then
    echo "Building Docker image..."
    # Remove existing image if it exists
    docker rmi tunnel-symfony 2>/dev/null || true
    docker build -t tunnel-symfony .
fi

# If config file is specified, add it to the arguments
if [ -n "$CONFIG_FILE" ]; then
    CONFIG_MOUNT="-v $(cd $(dirname "$CONFIG_FILE") && pwd)/$(basename "$CONFIG_FILE"):/data/src/tunnel-symfony/$(basename "$CONFIG_FILE"):ro"
    TUNNEL_ARGS+=("--config" "$(basename "$CONFIG_FILE")")
else
    CONFIG_MOUNT=""
fi

# Run docker command
docker run -it --rm \
  --network host \
  --privileged \
  -v ~/.aws:/root/.aws:ro \
  -v $(pwd):/data/src/tunnel-symfony \
  -v ~/.ssh/id_rsa:/root/.ssh/id_rsa:ro \
  -v $(ssh-agent -s | grep -o '/tmp/ssh-[^;]*')/agent.*:/ssh-agent \
  $CONFIG_MOUNT \
  -e SSH_AUTH_SOCK=/ssh-agent \
  -e AWS_PROFILE=${AWS_PROFILE:-default} \
  -e AWS_ACCESS_KEY_ID=${AWS_ACCESS_KEY_ID:-} \
  -e AWS_SECRET_ACCESS_KEY=${AWS_SECRET_ACCESS_KEY:-} \
  -e AWS_SESSION_TOKEN=${AWS_SESSION_TOKEN:-} \
  -e AWS_SECURITY_TOKEN=${AWS_SECURITY_TOKEN:-} \
  -e AWS_REGION=${AWS_REGION} \
  -e AWS_DEFAULT_REGION=${AWS_DEFAULT_REGION} \
  -e TERM=xterm-256color \
  tunnel-symfony sh -c "aws --version && \
    session-manager-plugin --version && \
    composer install --no-interaction && \
    php bin/tunnel ${TUNNEL_ARGS[*]} -vvv"
