<?php
namespace Nayjest\DbDump;

use Config;

class Scenario
{

    protected $data;

    protected $name;

    public function __construct($name)
    {
        if (!Config::has("db-dump::scenarios.$name")) {
            throw new \Exception("Wrong scenario name");
        }
        $this->name = $name;
        $this->data = Config::get("db-dump::scenarios.$name");
    }

    public function getTables()
    {
        return $this->data['tables'];
    }

    public function getName()
    {
        return $this->name;
    }
} 