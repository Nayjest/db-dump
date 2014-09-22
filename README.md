Database Dump Tool for Laravel
=======

#### Usage

* Create dump:

>    php artisan db:dump make


* Create dump with specific tags:

>    php artisan db:dump make --tags my_dump,some_other_tag

* Create dump using scenario:

>    php artisan db:dump make --scenario scenario_name

* Apply dump (interactive)

>    php artisan db:dump apply
