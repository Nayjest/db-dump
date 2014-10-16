<?php
namespace Nayjest\DbDump;

use App;
use DB;
use Illuminate\Console\Command;
use Config;
use SSH;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class DbDumpCommand extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'db:dump';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $command = $this->argument('cmd');
        if ($command === 'make') {
            if ($remote = $this->option('remote')) {
                $this->makeRemote($remote);
            } else {
                $this->make();
            }

        } elseif ($command === 'apply') {
            $this->apply();
        } else {
            $this->info("Unsupported command. use 'dump' or 'apply'");
        }
    }

    protected function beep()
    {
        echo chr(7), chr(7);
    }

    public function confirm($question, $default = false)
    {
        if ($this->option('no-input')) {
            return true;
        }
        return parent::confirm($question, $default);
    }

    protected function makeRemote($remote)
    {

        if (!$this->confirm("\tDump database on remote '$remote' server?")) {
            return;
        }

        $last_line = '';

        $options = '';
        if ($this->option('scenario')) {
            $options .= "-s " . $this->option('scenario');
        }
        if ($this->option('tags')) {
            $options .= " --tags " . $this->option('tags');
        }
        $command = "php artisan db:dump make --no-input 1 $options";
        $this->info("Executing on remote server: $command");
        SSH::into($remote)->run(
            [
                $this->getCdRootCommand($remote),
                $command
            ],
            function ($line) use (&$last_line) {
                $last_line = $line;
                echo '[ remote ] ', $line;
            }
        );


        if ($remote_path = $this->extractDataFromOutput($last_line)) {
            $this->downloadDump($remote, $remote_path);
        } else {
            $this->error("Can't download DB dump.");
        }
    }

    /**
     * @param $str
     * @return string|null
     */
    protected function extractDataFromOutput($str)
    {
        $matches = [];
        preg_match('/\[ (.*) \]/', $str, $matches);
        if (!empty($matches[1])) {
            return trim($matches[1]);
        }
    }

    protected function downloadDump($remote, $remote_path)
    {
        $parts = explode('/', $remote_path);
        $file_name = array_pop($parts);
        $this->info("Downloading $remote_path");
        $local_path = $this->option('path') . DIRECTORY_SEPARATOR . $file_name;
        SSH::into($remote)->get($remote_path, $local_path);
        $this->beep();
        $this->info("Done. See " . $local_path);
    }

    protected function generateDumpName($db, $env, $tags = [])
    {
        $tag_part = '';
        foreach ($tags as $tag) {
            $tag_part .= "_$tag";
        }
        $file_name = "db_{$db}_{$env}_" . date('Ymd.His') . $tag_part . '.sql.gz';
        return $file_name;
    }

    /**
     * Makes database dump
     */
    protected function make()
    {
        $db = $this->option('db');
        $env = App::environment();
        $tags = $this->getTags();
        $tag_part = '';
        if ($tags) {
            foreach ($tags as $tag) {
                $tag_part .= "_$tag";
            }
        }
        $file_name = $this->generateDumpName($db, $env, $this->getTags());
        $path = $this->option('path');
        $user = $this->option('user');
        $password = $this->option('password');
        $this->info("Details:");
        $this->info("\tDB name:\t$db");
        $this->info("\tDB user:\t$user");
        $this->info("\tEnv:\t$env");
        if ($sc = $this->option('scenario')) {
            $scenario = new Scenario($sc);
            $this->info("\tScenario:\t$sc");
            $this->info("\tTables to dump:\t" . join(', ', $scenario->getTables()));
        }
        $this->info("\tDate:\t" . date('Y-m-d H:i:s'));
        $this->info("\tTags:\t" . ($tags ? join(', ', $tags) : ''));
        $this->info("\tFile name:\t$path/$file_name");

        if ($this->confirm("\tDump database?")) {
            $this->info("Making dump...");
            if ($sc) {
                $tables = join(' ', $tables = $scenario->getTables());
            } else {
                $tables = '';
            }
            $command = "mysqldump --user=\"$user\" --password=\"$password\" $db $tables | gzip > $path/$file_name";
            $this->info("command: $command");
            system($command);
            $this->beep();
            $this->info("Done. See [ $path/$file_name ]");
        } else {
            $this->comment('Command Cancelled!');
        };
    }

    protected function getMySqlCommand($user, $password = null, $db = null)
    {
        return "mysql --user=\"$user\" --password=\"$password\" $db";
    }


    protected function createDB($db, $user, $password)
    {
        $this->info("Creating DB $db if not exists...");
        $mysql = $this->getMySqlCommand($user, $password);
        $sql = "CREATE DATABASE IF NOT EXISTS $db;";
        if (PHP_OS === 'WINNT') {
            system("echo $sql | $mysql");
        } else {
            system("echo \"$sql\" | $mysql");
        }
        $this->info("Done.");

    }

    protected function getCdRootCommand($remote)
    {
        return 'cd ' . Config::get("remote.connections.$remote.root");
    }

    protected function getTags()
    {
        $tags = $this->option('tags');
        if ($tags) {
            $tags = explode(',', $tags);
        } else {
            $tags = [];
        }
        $sc = $this->option('scenario');
        if ($sc) {
            $tags[] = "sc_$sc";
        }
        return $tags;
    }

    protected function apply()
    {
        $path = $this->option('path');
        $db = $this->option('db');
        $user = $this->option('user');
        $password = $this->option('password');

        $file = $this->chooseDump();
        if (!$file) {
            $this->comment('Dump file not selected, ending program');
            return false;
        }
        # remote apply
        if ($remote = $this->option('remote')) {
            $remote_path = $this->option('remote-path');
            $this->uploadDump(
                $remote,
                "$path/$file",
                "$remote_path/$file"
            );
            $db = $this->option('db');
            $command = "php artisan db:dump apply --file=\"$file\" --path=\"$remote_path\" --no-input=\"1\" --create-db=\"1\" --db=\"$db\"";
            SSH::into($remote)->run(
                [
                    $this->getCdRootCommand($remote),
                    $command
                ], function ($line) {
                    echo '[ remote ] ', $line;
                }
            );
            $this->beep();
            echo "Done.";
            return true;
        }

        if ($this->confirm("Apply $path/$file to $db?")) {

            if ($this->option('create-db')) {
                $this->createDB($db, $user, $password);
            }

            $mysql = $this->getMySqlCommand($user, $password, $db);
            $command = "gunzip -c $path/$file | $mysql";
            $this->info("command: $command");
            system($command);
            $this->info("Done");
        } else {
            $this->comment('Command Cancelled!');
            return false;
        };
        return true;
    }

    protected function uploadDump($remote, $local_path, $remote_path)
    {
        $this->info("Uploading dump '$local_path' into '$remote_path' at '$remote'...");
        SSH::into($remote)->put($local_path, $remote_path);
        $this->info("Done uploading.");
    }

    protected function getDumpsListCommand($path, $tags = [])
    {
        $command = "ls $path | grep \".sql.gz\"";
        if ($tags) {
            foreach ($tags as $tag) {
                $command .= "| grep \"$tag\"";
            }
        }
        return $command;
    }

    protected function listDumps($path, $tags = [])
    {
        $command = $this->getDumpsListCommand($path, $tags);
        $output = shell_exec($command);
        $lines = explode("\n", $output);
        return $lines;
    }

    protected function chooseDump()
    {
        if ($this->option('file')) {
            return $this->option('file');
        }

        $this->info("Select dump:");

        $dumps = $this->listDumps($this->option('path'), $this->getTags());
        foreach ($dumps as $i => $line) {
            $id = $i + 1;
            if (trim($line)) {
                $this->info("\t$id:\t$line");
            }
        }
        $id = $this->ask("Enter dump ID: ");
        if (!is_numeric($id) or empty($dumps[$id - 1])) {
            if ($this->confirm("Wrong dump ID. Try again?")) {
                return $this->chooseDump();
            } else {
                return null;
            }
        }
        return trim($dumps[$id - 1]);
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['cmd', InputArgument::REQUIRED, 'Command (make|apply)'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['db', null, InputOption::VALUE_OPTIONAL, 'Target DB name.', DB::connection(DB::getDefaultConnection())->getDatabaseName()],
            ['user', null, InputOption::VALUE_OPTIONAL, 'Target DB user.', DB::connection(DB::getDefaultConnection())->getConfig('username')],
            ['password', null, InputOption::VALUE_OPTIONAL, 'DB password.', DB::connection(DB::getDefaultConnection())->getConfig('password')],
            ['path', 'p', InputOption::VALUE_OPTIONAL, 'Path to dumps.', Config::get('db-dump::path')],
            ['remote-path', null, InputOption::VALUE_OPTIONAL, 'Remote path', Config::get('db-dump::path')],
            ['tags', 't', InputOption::VALUE_OPTIONAL, 'Specify dump tags (comma-separated).', null],
            ['scenario', 's', InputOption::VALUE_OPTIONAL, 'Scenario (scenarios must be specified in package configuration).', null],
            ['remote', 'r', InputOption::VALUE_OPTIONAL, 'Execute command on remote host.', null],
            ['no-input', 'y', InputOption::VALUE_OPTIONAL, 'Do not ask questions.', null],
            ['file', 'f', InputOption::VALUE_OPTIONAL, 'Dump file to apply.', null],
            ['create-db', 'c', InputOption::VALUE_OPTIONAL, 'Create DB before applying dump.', null],
        ];
    }
}
