<?php
namespace Nayjest\DbDump;

use App;
use DB;
use Illuminate\Console\Command;
use Config;
use SSH;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class DbDumpCommand extends Command {

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
                SSH::into($remote)->run(
                    [   'cd ' . Config::get("remote.connections.$remote.root"),
                        'php artisan db:dump make -y'
                    ],
                    function($line) {
                        echo $line, PHP_EOL;
                    }
                );
                return;
            }
            $this->make();
        } elseif ($command === 'apply') {
            $this->apply();
        } else {
            $this->info("Unsupported command. use 'dump' or 'apply'");
        }
	}

    protected function make()
    {

        $no_input = $this->input->hasOption('no-input');
        $db = $this->option('db');
        $env = App::environment();
        $tags = $this->getTags();
        $tag_part = '';
        if ($tags) {
            foreach($tags as $tag) {
                $tag_part.= "_$tag";
            }
        }
        $file_name = "db_{$db}_{$env}_" . date('Ymd.His') . $tag_part . '.sql.gz';
        $path = $this->option('path');
        $user = $this->option('user');
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
        $this->info("\tTags:\t" . ($tags?join(', ', $tags):''));
        $this->info("\tFile name:\t$path/$file_name");

        if ($no_input or $this->confirm("\tDump database?", false)) {
            $this->info("Making dump...");
            if ($sc) {
                $tables = join(' ', $tables = $scenario->getTables());
            } else {
                $tables = '';
            }
            $command = "mysqldump -u$user -p $db $tables | gzip > $path/$file_name";
            $this->info("command: $command");
            system($command);
            $this->info("Done. See $path/$file_name");
        } else {
            $this->comment('Command Cancelled!');
            return false;
        };
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
        $this->info("Select dump:");
        $file = $this->choose();
        if (!$file) {
            $this->comment('Dump file not selected, ending program');
            return false;
        }
        $path = $this->option('path');
        $db = $this->option('db');
        $user = $this->option('user');
        if ($this->confirm("Apply $path/$file to $db?")) {
            $command = "gunzip -c $path/$file | mysql -u$user -p $db";
            $this->info("command: $command");
            system($command);
            $this->info("Done");
        } else {
            $this->comment('Command Cancelled!');
            return false;
        };
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

    protected function choose()
    {

        $dumps = $this->listDumps($this->option('path'), $this->getTags());
        foreach ($dumps as $i => $line) {
            $id = $i+1;
            if (trim($line)) {
                $this->info("\t$id:\t$line");
            }
        }
        $id = $this->ask("Enter dump ID: ");
        if (!is_numeric($id) or empty($lines[$id-1])) {
            if ($this->confirm("Wrong dump ID. Try again?")) {
                return $this->choose();
            } else {
                return null;
            }
        }
        return trim($lines[$id-1]);
    }

	/**
	 * Get the console command arguments.
	 *
	 * @return array
	 */
	protected function getArguments()
	{
		return [
			['cmd', InputArgument::REQUIRED, 'Command (apply)'],
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
            ['user', 'u', InputOption::VALUE_OPTIONAL, 'Target DB user.', DB::connection(DB::getDefaultConnection())->getConfig('username')],
            ['path', 'p', InputOption::VALUE_OPTIONAL, 'Path to dumps.', Config::get('db-dump::path')],
            ['tags', 't', InputOption::VALUE_OPTIONAL, 'Specify dump tags (comma-separated).', null],
            ['scenario', 's', InputOption::VALUE_OPTIONAL, 'Scenario (scenarios must be specified in package configuration).', null],
            ['remote', 'r', InputOption::VALUE_OPTIONAL, 'Execute command on remote host.', null],
            ['no-input', 'y', InputOption::VALUE_OPTIONAL, 'Do not ask questions.', null],
        ];
	}

}
