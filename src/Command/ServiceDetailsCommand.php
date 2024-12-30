<?php

namespace App\Command;

use App\Service\TunnelManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class ServiceDetailsCommand extends Command
{
    protected static $defaultName = 'service-details';

    public function __construct(private TunnelManager $tunnelManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Get service details from SSM parameters')
            ->addOption('services', 's', InputOption::VALUE_REQUIRED, 'Comma-separated list of services')
            ->addOption('environment', 'e', InputOption::VALUE_REQUIRED, 'Environment name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $services = explode(',', $input->getOption('services'));
            $environment = $input->getOption('environment');
            $verbose = $input->getOption('verbose');

            $this->tunnelManager->setEnvironment($environment);
            $this->tunnelManager->setVerbose($verbose);

            foreach ($services as $serviceName) {
                $details = $this->tunnelManager->getServiceDetails($serviceName);
                $output->writeln(sprintf("\nService: %s", $serviceName));
                foreach ($details as $key => $value) {
                    $output->writeln(sprintf("%s=%s", $key, $value));
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
