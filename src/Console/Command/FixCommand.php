<?php
namespace Wuelto\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Yaml\Parser;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Description of FixCommand
 *
 * @author recchia
 */
class FixCommand extends Command
{
    protected function configure()
    {
        $this
                ->setName("wuelto:fix")
                ->setDescription("Fix bug on Item Lists");
    }
    
    protected function Log($name, $message)
    {
        $log = new Logger($name);
        $log->pushHandler(new StreamHandler(__DIR__ . '/../../../log/' . $name . '.log', Logger::WARNING));
        $log->addWarning($message);
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $success = 0;
        $fails = 0;
        $target = $this->getTargetConnection($output);
        $strSQL = "SELECT COUNT(`wl_items`.`id`) FROM `wuelto`.`wl_items`;";
        $stmt = $target->query($strSQL);
        $total = $stmt->fetchColumn();
        if ($total > 0) {
            $query = "SELECT `wl_items`.`id`, `wl_items`.`user_id`, `wl_items`.`collection_id`, `wl_items`.`collection_name` FROM `wl_items`";
            $output->writeln("");
            $bar = new ProgressBar($output, $total);
            $bar->setFormat("<comment> %message%\n %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%</comment>");
            $bar->setMessage('<comment>Fixing bug in Item Lists...</comment>');
            $_rs = $target->executeQuery($query);
            $bar->start();
            while ($item = $_rs->fetch(\PDO::FETCH_ASSOC)) {
                try {
                    $name = trim($item['collection_name']);
                    $query = "SELECT wl_itemlists.id FROM wl_itemlists WHERE user_id = ? AND TRIM(lists) = ?";
                    $stmt = $target->executeQuery($query, array($item['user_id'], trim($item['collection_name'])));
                    $list_id = $stmt->fetchColumn();
                    if (isset($list_id)) {
                        $target->update('wl_items', array('collection_id' => $list_id), array('id' => $item['id']));
                        $success++;
                    }
                } catch (\Doctrine\DBAL\Exception\DriverException $ex) {
                    $this->Log('target', "Couldn't save item {$item['id']}. Info: " . $ex->getMessage());
                    $fails++;
                } catch (\Doctrine\DBAL\Driver\PDOException $ex) {
                    $this->Log('target', "Couldn't save item {$item['id']}. Info: " . $ex->getMessage());
                    $fails++;
                }
                $bar->advance();
            }
            $bar->setMessage("Proccess Done");
            $bar->clear();
            $bar->display();
            $bar->finish();
            $output->writeln("");
            $output->writeln("<info>Fixing Done</info>");
            $output->writeln("<info>Total: $total</info>");
            $output->writeln("<info>Fixing: $success</info>");
            $output->writeln("<info>Items Not Fixed: $fails</info>");
        }
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
        $this->targetName = $_data['target']['dbname'];
        
        return DriverManager::getConnection($connectionParams, $config);
    }
}
