<?php
namespace Wuelto\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends Command
{

    protected function configure()
    {
        $this
                ->setName("wuelto:migrate")
                ->setDescription("Migrate Wuelto Databases from different Countries")
                ->addArgument("country", InputArgument::REQUIRED, "Country Name For Migration")
                ->addOption("delete-duplicate-target", "ddt", InputOption::VALUE_NONE, "If set, delete duplicate on target database")
        ;
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $country = $input->getArgument("country");
        if($country) {
            $output->writeln($country);
        }
    }

}
