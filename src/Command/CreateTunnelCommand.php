<?php

namespace App\Command;

use App\Service\TunnelManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class CreateTunnelCommand extends Command
{
    protected static $defaultName = 'create-tunnel';

    public function __construct(private TunnelManager $tunnelManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create SSM port forwarding tunnels')
            ->addOption('services', 's', InputOption::VALUE_REQUIRED, 'Comma-separated list of services')
            ->addOption('environment', 'e', InputOption::VALUE_REQUIRED, 'Environment name')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to configuration file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $services = explode(',', $input->getOption('services'));
            $environment = $input->getOption('environment');
            $verbose = $input->getOption('verbose');
            $configFile = $input->getOption('config');

            if ($configFile) {
                $this->tunnelManager->loadConfig($configFile);
            }

            $this->tunnelManager->setEnvironment($environment);
            $this->tunnelManager->setVerbose($verbose);

            $this->tunnelManager->createTunnels($services);

            $output->writeln('Tunnels created successfully. Press Ctrl+C to exit and close all tunnels');

            // Wait for interrupt signal
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use ($output) {
                $output->writeln("\nReceived interrupt signal, cleaning up...");
                $this->tunnelManager->cleanup();
                exit(0);
            });

            while (true) {
                sleep(1);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
