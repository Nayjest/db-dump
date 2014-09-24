Database Dump Tool for Laravel
=======


## Installation

#### Installation with composer

* Step 1: Add git url to composer.json file in your project:
```
"repositories": [
    {
        "url": "git@github.com:Nayjest/db-dump.git",
        "type": "git"
    }
],
```
* Step 2: Add dependency to "require" section
```
"require": {
    "nayjest/db-dump": "~1"
},
```
* Step 3: run "composer update" command
* 

## Usage

Create dump:

>    php artisan db:dump make


Create dump with specific tags:

>    php artisan db:dump make --tags my_dump,some_other_tag

Create dump using scenario:

>    php artisan db:dump make --scenario scenario_name

Apply dump (interactive)

>    php artisan db:dump apply
