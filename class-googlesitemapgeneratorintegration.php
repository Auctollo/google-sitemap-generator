<?php
/**
 * 
 *
 * @author Auctollo
 * @package sitemap
 * @since 4.0
 */

 class Google_Sitemap_Generator_Integration {

	public array $list;
	public array $options;
	public array $integrations;
	protected static $instance;

	public function __construct() {
		$this->set_integrations_list();
		$this->set_options();
		$this->init();
	}

	public function set_integrations_list() {
		$this->list = [
			'asgaros-forum/asgaros-forum.php' => 'Google_Sitemap_Generator_Integration_Asgaros'
		];
	}

	public function set_options() {
		$this->options = [
			'name' => 'sm_integration'
		];
	}

	public function init() {
		/**
		 * Detect plugin. For frontend only.
		 */
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		foreach ( $this->list as $index_path => $class_name ) {
			if ( is_plugin_active ( $index_path ) ) {
				$this->integrations[] = new $class_name;
			}
		}
	}

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}
		return self::$instance;
	}
}

class Google_Sitemap_Generator_Integration_Asgaros {

	public $title;
	public $name;

	public function __construct() {
		$this->title = 'Asgaros';
		$this->name = 'asgaros';
	}
}

// $gsgi = new Google_Sitemap_Generator_Integration;
// var_dump($gsgi);
// wp_die();
