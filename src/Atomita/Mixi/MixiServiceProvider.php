<?php

namespace Atomita\Mixi;

use Illuminate\Support\ServiceProvider;

class MixiServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot() {
		$this->package('atomita/mixi');
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register() {
		$this->app['mixi'] = $this->app->share(function($app) {
			$config = $app['config']->get('mixi::config');
//			if (!in_array($config['display'], array('touch', 'pc', 'smartphone', 'ios'))) {
//				$config['display'] = 'touch';
//			}
			return new Mixi($config);
		});
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides() {
		return array();
	}

}
