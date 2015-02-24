<?php
namespace Wuelto\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Exception\DumpException;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Driver\PDOException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;

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
        if ($country) {
            $this->checkTargetFile($input, $output);
            $this->checkCountryFile($input, $output, $country);
            $source = $this->getSourceConnection($output, $country);
            $target = $this->getTargetConnection($output);
            try {
                $this->createIdColumns($output, $target);
                $sth = $source->query("SELECT count(id) AS total FROM wl_users");
                $total = $sth->fetchColumn();
                $output->writeln("<info>$total Usuarios</info>");
            } catch (ConnectionException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
            } catch (PDOException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
            } catch (InvalidFieldNameException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
            }
        }
    }

    protected function checkTargetFile(InputInterface $input, OutputInterface $output)
    {
        if (!$this->fs->exists(__DIR__ . '/../../../config/target.yml')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('<info>The target configuration file does not exist, want to create? [y/n](y):</info> ', true);
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
        $output->writeln('<info>The configuration file target.yml has been created.</info>');
    }
    
    protected function checkCountryFile(InputInterface $input, OutputInterface $output, $country)
    {
        $filename = strtolower($country . '.yml');
        if (!$this->fs->exists(__DIR__ . '/../../../config/' . $filename)) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('<info>The configuration file ' . $filename . ' does not exist, want to create? [y/n](y):</info> ', true);
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
    
    protected function getSourceConnection(OutputInterface $output, $country)
    {
        $filename = strtolower($country . '.yml');
        $yaml = new Parser();
        try {
            $_data = $yaml->parse(file_get_contents(__DIR__ . '/../../../config/' . $filename));
        } catch (ParseException $e) {
            $output->writeln('<error>Unable to parse the YAML string: ' . $e->getMessage() . '</error>');
            
            return false;
        }
        $config = new Configuration();
        $connectionParams = array(
            'dbname' => $_data['source']['dbname'],
            'user' => $_data['source']['user'],
            'password' => $_data['source']['password'],
            'host' => $_data['source']['host'],
            'port' => 3306,
            'charset' => 'utf8',
            'driver' => 'pdo_mysql',
        );
        
        return DriverManager::getConnection($connectionParams, $config);
    }
    
    protected function getTargetConnection(OutputInterface $output)
    {
        $yaml = new Parser();
        try {
            $_data = $yaml->parse(file_get_contents(__DIR__ . '/../../../config/target.yml'));
        } catch (ParseException $e) {
            $output->writeln('<error>Unable to parse the YAML string: ' . $e->getMessage() . '</error>');
            
            return false;
        }
        $config = new Configuration();
        $connectionParams = array(
            'dbname' => $_data['target']['dbname'],
            'user' => $_data['target']['user'],
            'password' => $_data['target']['password'],
            'host' => $_data['target']['host'],
            'port' => 3306,
            'charset' => 'utf8',
            'driver' => 'pdo_mysql',
        );
        
        return DriverManager::getConnection($connectionParams, $config);
    }
    
    protected function createIdColumns(OutputInterface $output, $target)
    {
        $output->writeln('');
        $_tables = array('users' => 'wl_users', 'shops' => 'wl_shops', 'items' => 'wl_items');
        $_lastFields = array('users' => 'seller_ratings', 'shops' => 'created_on', 'items' => 'bm_redircturl');
        $progress = new ProgressBar($output, 3);
        $progress->setFormat("<comment> %message%\n %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%</comment>");
        $progress->setMessage("Verifying special columns");
        $progress->start();
        foreach ($_tables as $key => $value) {
            $sth = $target->query("SELECT count(*) as 'exist' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'wuelto' AND TABLE_NAME = '$value' AND COLUMN_NAME = 'old_id';");
            $exist = $sth->fetchColumn();
            if (!$exist) {
                $progress->clear();
                $progress->setMessage("The field does not exist in the table $key, creating...");
                $progress->display();
                $sth = $target->query("ALTER TABLE `wuelto`.`$value` ADD COLUMN `old_id` INT(32) NULL AFTER `{$_lastFields[$key]}`;");
                $progress->clear();
                $progress->setMessage("The field was created in the table $key");
                $progress->display();
            } else {
                $progress->clear();
                $progress->setMessage("The field already exists in the $key table");
                $progress->display();
            }
            $progress->advance();
        }
        $progress->clear();
        $progress->setMessage('<comment>Verification finished.</comment>');
        $progress->display();
        $progress->finish();
        $output->writeln('');
    }

}
