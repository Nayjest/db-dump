<?php namespace Nayjest\DbDump;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
        $this->app['db_dump_command'] = $this->app->share(function($app)
        {
            return new DbDumpCommand;
        });
        $this->commands(['db_dump_command']);
	}

    public function boot()
    {
        $this->package('nayjest/db-dump');
    }

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array('nayjest/db-dump');
	}

}
