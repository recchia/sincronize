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
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class MigrateCommand extends Command
{
    private $fs;
    private $countryId;
    private $currencyId;
    private $dbName;
    
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
    
    protected function Log($name, $message)
    {
        if (!$this->fs->exists(__DIR__ . '/../../../log')) {
            $this->fs->mkdir(__DIR__ . '/../../../log');
        }
        $log = new Logger($name);
        $log->pushHandler(new StreamHandler(__DIR__ . '/../../../log/' . $name . '.log', Logger::WARNING));
        $log->addWarning($message);
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
                $this->executeFirstPhaseMigration($source, $target, $output);
            } catch (ConnectionException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
            } catch (PDOException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
            } catch (InvalidFieldNameException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
            } catch (\Doctrine\DBAL\Exception\SyntaxErrorException $e) {
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
        $country_id = (int)$this->doQuestion($input, $output, 'Please enter the country id:', 'Must enter a country id');
        $currency_id = (int)$this->doQuestion($input, $output, 'Please enter the currency id:', 'Must enter a currency id');
        $config = array('source' => array('host' => $host, 'dbname' => $dbname, 'user' => $user, 'password' => $password, 'country_id' => $country_id, 'currency_id' => $currency_id));
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
        $this->dbName = $_data['source']['dbname'];
        $this->countryId = $_data['source']['country_id'];
        $this->currencyId = $_data['source']['currency_id'];
        
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
        $progress->setMessage('<comment>Verification finished. Special fields are ok</comment>');
        $progress->display();
        $progress->finish();
        $output->writeln('');
    }
    
    protected function executeFirstPhaseMigration(\Doctrine\DBAL\Connection $source, \Doctrine\DBAL\Connection $target, OutputInterface $output)
    {
        $output->writeln('');
        $sth = $source->query("SELECT count(id) AS total FROM wl_users");
        $total = $sth->fetchColumn();
        $bar = new ProgressBar($output, $total);
        $bar->setFormat("<comment> %message%\n %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%</comment>");
        $bar->setMessage('<comment>Migrating users, stores and products...</comment>');
        $stmt = $source->query($this->getUsersQuery());
        $bar->start();
        while ($user = $stmt->fetch()) {
            $sth = $target->executeQuery('SELECT count(id) as "count" FROM wl_users WHERE email = ?', array($user['email']));
            $count = $sth->fetchColumn();
            if ($count == 0) {
                $new_user_id = $this->insertUsert($user, $target);
                if (!$new_user_id) {
                    $this->Log($this->dbName, "Couldn't save the user {$user['email']} with Id {$user['id']}");
                } else {
                    $shop = $this->getShop($source, $user['id']);
                    if (count($shop) > 0) {
                        $new_shop_id = $this->insertShop($target, $shop, $new_user_id);
                        if (!$new_shop_id) {
                            $this->Log($this->dbName, "Couldn't save the shop {$shop['shop_name']} with Id {$shop['id']}");
                        } else {
                            $items = $this->getItems($source, $user['id'], $shop['id']);
                        }
                    }
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $output->writeln('');
        $output->writeln("<info>$total Usuarios</info>");
    }

    protected function getUsersQuery()
    {
        $query = "SELECT `wl_users`.`id`, `wl_users`.`username`, `wl_users`.`username_url`, `wl_users`.`first_name`, `wl_users`.`last_name`, ";
        $query.= "`wl_users`.`password`, `wl_users`.`email`, `wl_users`.`city`, `wl_users`.`website`, `wl_users`.`birthday`, `wl_users`.`age_between`, ";
        $query.= "`wl_users`.`user_level`, `wl_users`.`user_status`, `wl_users`.`profile_image`, `wl_users`.`location`, `wl_users`.`about`, ";
        $query.= "`wl_users`.`created_at`, `wl_users`.`modified_at`, `wl_users`.`follow_count`, `wl_users`.`login_type`, `wl_users`.`facebook_id`, ";
        $query.= "`wl_users`.`token_key`, `wl_users`.`secret_key`, `wl_users`.`twitter_id`, `wl_users`.`twitter`, `wl_users`.`google_id`, ";
        $query.= "`wl_users`.`facebook_session`, `wl_users`.`twitter_session`, `wl_users`.`referrer_id`, `wl_users`.`credit_total`, `wl_users`.`refer_key`, ";
        $query.= "`wl_users`.`gender`, `wl_users`.`user_address`, `wl_users`.`last_login`, `wl_users`.`activation`, `wl_users`.`subs`, ";
        $query.= "`wl_users`.`someone_follow`, `wl_users`.`someone_show`, `wl_users`.`someone_cmnt_ur_things`, `wl_users`.`your_thing_featured`, ";
        $query.= "`wl_users`.`someone_mention_u`, `wl_users`.`push_notifications`, `wl_users`.`unread_notify_cnt`, `wl_users`.`featureditemid`, ";
        $query.= "`wl_users`.`defaultshipping`, `wl_users`.`user_api_details`, `wl_users`.`seller_ratings` FROM `{$this->dbName}`.`wl_users`";
        
        return $query;
    }
    
    protected function insertUsert($row, \Doctrine\DBAL\Connection $target)
    {
        $_values = array(
            'username' => $row['username'],
            'username_url' => $row['username_url'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'password' => $row['password'],
            'email' => $row['email'],
            'city' => $row['city'],
            'website' => $row['website'],
            'birthday' => $row['birthday'],
            'age_between' => $row['age_between'],
            'user_level' => $row['user_level'],
            'user_status' => $row['user_status'],
            'profile_image' => $row['profile_image'],
            'about' => $row['about'],
            'created_at' => $row['created_at'],
            'modified_at' => $row['modified_at'],
            'follow_count' => $row['follow_count'],
            'login_type' => $row['login_type'],
            'facebook_id' => $row['facebook_id'],
            'token_key' => $row['token_key'],
            'secret_key' => $row['secret_key'],
            'twitter_id' => $row['twitter_id'],
            'twitter' => $row['twitter'],
            'google_id' => $row['google_id'],
            'facebook_session' => $row['facebook_session'],
            'twitter_session' => $row['twitter_session'],
            'referrer_id' => $row['referrer_id'],
            'credit_total' => $row['credit_total'],
            'refer_key' => $row['refer_key'],
            'gender' => $row['gender'],
            'user_address' => $row['user_address'],
            'last_login' => $row['last_login'],
            'activation' => $row['activation'],
            'subs' => $row['subs'],
            'someone_follow' => $row['someone_follow'],
            'someone_show' => $row['someone_show'],
            'someone_cmnt_ur_things' => $row['someone_cmnt_ur_things'],
            'your_thing_featured' => $row['your_thing_featured'],
            'someone_mention_u' => $row['someone_mention_u'],
            'push_notifications' => $row['push_notifications'],
            'unread_notify_cnt' => $row['unread_notify_cnt'],
            'featureditemid' => $row['featureditemid'],
            'defaultshipping' => $row['defaultshipping'],
            'user_api_details' => $row['user_api_details'],
            'seller_ratings' => $row['seller_ratings'],
            'old_id' => $row['id']
        );
        if (!$target->insert('wl_users', $_values)) {
            return false;
        } else {
            return $target->lastInsertId();
        }
    }
    
    protected function getShop(\Doctrine\DBAL\Connection $source, $user_id)
    {
        $query = "SELECT `wl_shops`.`id`, `wl_shops`.`user_id`, `wl_shops`.`shop_name`, `wl_shops`.`shop_title`, `wl_shops`.`desc`, `wl_shops`.`shop_image`, ";
        $query.= "`wl_shops`.`shop_banner`, `wl_shops`.`shop_announcement`, `wl_shops`.`shop_message`, `wl_shops`.`shop_address`, `wl_shops`.`shop_latitude`, ";
        $query.= "`wl_shops`.`shop_longitude`, `wl_shops`.`item_count`, `wl_shops`.`follow_count`, `wl_shops`.`welcome_message`, `wl_shops`.`payment_policy`, ";
        $query.= "`wl_shops`.`shipping_policy`, `wl_shops`.`refund_policy`, `wl_shops`.`additional_info`, `wl_shops`.`seller_info`, `wl_shops`.`phone_no`, ";
        $query.= "`wl_shops`.`paypal_id`, `wl_shops`.`seller_status`, `wl_shops`.`created_on` FROM `{$this->dbName}`.`wl_shops` WHERE `wl_shops`.`user_id` = ?";
        $shop = $source->executeQuery($query, array($user_id));
        
        return $shop->fetch(\PDO::FETCH_ASSOC);
    }
    
    protected function insertShop(\Doctrine\DBAL\Connection $target, $shop, $new_user_id)
    {
        $_values = array(
            'user_id' => $user_id,
            'shop_name' => $shop['shop_name'],
            'shop_title' => $shop['shop_title'],
            'desc' => $shop['desc'],
            'shop_image' => $shop['shop_image'],
            'shop_banner' => $shop['shop_banner'],
            'shop_announcement' => $shop['shop_announcement'],
            'shop_message' => $shop['shop_message'],
            'shop_address' => $shop['shop_address'],
            'shop_latitude' => $shop['shop_latitude'],
            'shop_longitude' => $shop['shop_longitude'],
            'item_count' => $shop['item_count'],
            'follow_count' => $shop['follow_count'],
            'welcome_message' => $shop['welcome_message'],
            'payment_policy' => $shop['payment_policy'],
            'shipping_policy' => $shop['shipping_policy'],
            'refund_policy' => $shop['refund_policy'],
            'additional_info' => $shop['additional_info'],
            'seller_info' => $shop['seller_info'],
            'phone_no' => $shop['phone_no'],
            'paypal_id' => $shop['paypal_id'],
            'seller_status' => $shop['seller_status'],
            'created_on' => $shop['created_on'],
            'shop_name' => $shop['shop_name'],
            'old_id' => $shop['id']
        );
        if (!$target->insert('wl_shops', $_values)) {
            return false;
        } else {
            return $target->lastInsertId();
        }
    }
    
    protected function getItems(\Doctrine\DBAL\Connection $source, $user_id, $shop_id)
    {
        $query = "SELECT `wl_items`.`id`, `wl_items`.`user_id`, `wl_items`.`shop_id`, `wl_items`.`item_title`, `wl_items`.`item_title_url`, ";
        $query.= "`wl_items`.`item_description`, `wl_items`.`recipient`, `wl_items`.`occasion`, `wl_items`.`style`, `wl_items`.`tags`, `wl_items`.`materials`, ";
        $query.= "`wl_items`.`price`, `wl_items`.`quantity`, `wl_items`.`quantity_sold`, `wl_items`.`collection_id`, `wl_items`.`collection_name`, ";
        $query.= "`wl_items`.`category_id`, `wl_items`.`general_category`, `wl_items`.`super_catid`, `wl_items`.`sub_catid`, `wl_items`.`ship_from_country`, ";
        $query.= "`wl_items`.`processing_time`, `wl_items`.`size_options`, `wl_items`.`status`, `wl_items`.`created_on`, `wl_items`.`modified_on`, ";
        $query.= "`wl_items`.`item_color`, `wl_items`.`featured`, `wl_items`.`fav_count`, `wl_items`.`comment_count`, `wl_items`.`hashtag`, ";
        $query.= "`wl_items`.`report_flag`, `wl_items`.`bm_redircturl` FROM `{$this->dbName}`.`wl_items` WHERE `wl_items`.`user_id` = ? AND `wl_items`.`shop_id` = ?";
        $items = $source->executeQuery($query, array($user_id, $shop_id));
        
        return $items->fetchAll(\PDO::FETCH_ASSOC);
    }

}
