<?php
namespace Wuelto\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Exception\DumpException;

class MigrateCommand extends Command
{
    private $fs;
    
    public function __construct($name = null)
    {
        parent::__construct($name);
        $this->fs = new Filesystem();
    }

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
            $this->checkCountryFile($input, $output, $country);
            $output->writeln("<info>$country</info>");
        }
    }
    
    protected function checkTargetFile(InputInterface $input, OutputInterface $output)
    {
        if (!$this->fs->exists(__DIR__ . '/../../../config/target.yml')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('<info>The target configuration file does not exist, want to create? (y):</info> ', true);
            if (!$helper->ask($input, $output, $question)) {
                return;
            }
            $this->createTargetFile($input, $output);
        }
        
        return;
    }
    
    protected function createTargetFile(InputInterface $input, OutputInterface $output)
    {
        $host = $this->doQuestion($input, $output, 'Please enter the host: ', 'Must enter a hostname or IP');
        $dbname = $this->doQuestion($input, $output, 'Please enter the database name: ', 'Must enter a database name');
        $user = $this->doQuestion($input, $output, 'Please enter the username: ', 'Must enter a username');
        $password = $this->doQuestion($input, $output, 'Please enter the password: ', 'Must enter a password');
        $config = array('source' => array('host' => $host, 'dbname' => $dbname, 'user' => $user, 'password' => $password));
        try {
            $dumper = new Dumper();
            $yaml = $dumper->dump($config, 2);
            $this->fs->dumpFile(__DIR__ . '/../../../config/target.yml', $yaml);
        } catch (DumpException $e) {
            $output->writeln("<error>An error occurred while dumping configuration " . $e->getMessage() . "</error>");
        } catch (IOExceptionInterface $e) {
            $output->writeln("<error>An error occurred while creating file $filename in path " . $e->getPath() . "</error>");
        }
    }
    
    protected function checkCountryFile(InputInterface $input, OutputInterface $output, $country)
    {
        $filename = strtolower($country . '.yml');
        if (!$this->fs->exists(__DIR__ . '/../../../config/' . $filename)) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('<info>The configuration file ' . $filename . ' does not exist, want to create? (y):</info> ', true);
            if (!$helper->ask($input, $output, $question)) {
                return;
            }
            $this->createCountryFile($input, $output, $filename);
        }
        
        return;
    }
    
    protected function createCountryFile(InputInterface $input, OutputInterface $output, $filename)
    {
        $host = $this->doQuestion($input, $output, 'Please enter the host: ', 'Must enter a hostname or IP');
        $dbname = $this->doQuestion($input, $output, 'Please enter the database name: ', 'Must enter a database name');
        $user = $this->doQuestion($input, $output, 'Please enter the username: ', 'Must enter a username');
        $password = $this->doQuestion($input, $output, 'Please enter the password: ', 'Must enter a password');
        $config = array('source' => array('host' => $host, 'dbname' => $dbname, 'user' => $user, 'password' => $password));
        try {
            $dumper = new Dumper();
            $yaml = $dumper->dump($config, 2);
            $this->fs->dumpFile(__DIR__ . '/../../../config/' . $filename, $yaml);
        } catch (DumpException $e) {
            $output->writeln("<error>An error occurred while dumping configuration " . $e->getMessage() . "</error>");
        } catch (IOExceptionInterface $e) {
            $output->writeln("<error>An error occurred while creating file $filename in path " . $e->getPath() . "</error>");
        }
        $output->writeln('<info>The configuration file '. $filename .' has been created.</info>');
    }

    protected function doQuestion(InputInterface $input, OutputInterface $output, $question, $errorMsg)
    {
        $helper = $this->getHelper('question');
        $question = new Question('<question>' . $question . '</question>');
        $question->setValidator(function ($answer) use ($errorMsg) {
            if (empty($answer)) {
                throw new \RuntimeException(
                $errorMsg
                );
            }
            return $answer;
        });
        $question->setMaxAttempts(null);
        $string = $helper->ask($input, $output, $question);

        return $string;
    }

}
