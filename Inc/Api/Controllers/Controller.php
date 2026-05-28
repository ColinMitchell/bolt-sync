<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Api\Controllers;

use BoltSync\Inc\Plugin;
use BoltSync\Inc\Services\Core;

/**
 * Base class for REST API controllers.
 * Receives a single shared Core instance via constructor injection.
 */
abstract class Controller {

	/**
	 * @var Core
	 */
	protected Core $core;

	/**
	 * @var Plugin
	 */
	protected Plugin $plugin;

	public function __construct( Plugin $plugin, Core $core ) {
		$this->plugin = $plugin;
		$this->core   = $core;
	}

	/**
	 * Flushes the bolt_sync object cache group.
	 *
	 * @return bool
	 */
	public function flush_cache(): bool {
		return $this->core->flush_cache();
	}
}
