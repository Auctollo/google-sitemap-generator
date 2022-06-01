<?php
require dirname( dirname( __FILE__ ) ) . '/sitemap-core.php';
require_once '/var/www/html/wordpress-5.8.1/wordpress/wp-load.php';
/**
 * Test case for create page .
 */
class OldVersion {
	/**
	 * Test function .
	 */
	public static function test() {
		self::test_create_pages();
	}
	/**
	 * GoogleSitemapGenerator variable
	 *
	 * @var GoogleSitemapGenerator .
	 */
	public static $gsg;
	/**
	 * Create pages function .
	 */
	public static function test_create_pages() {
		self::$gsg = new GoogleSitemapGenerator();
		self::$gsg->initate();
		self::$gsg->set_option( 'sm_links_page', 5 );
		$test_pages = array(
			array(
				'url'  => 'http://www.foo.com/index.html',
				'lst'  => '2022-12-22',
				'cf'   => 'monthly',
				'prio' => 0.3,
				'pid'  => 22,
			),
			array(
				'url'  => 'http://www.foo.com/index.html/1st',
				'lst'  => '2022-12-22',
				'cf'   => 'weekly',
				'prio' => 0.2,
				'pid'  => 225,
			),
			array(
				'url'  => 'http://www.foo.com/index.html/2nd',
				'lst'  => '2022-12-22',
				'cf'   => 'daily',
				'prio' => 0.5,
				'pid'  => 221,
			),
			array(
				'url'  => 'http://www.foo.com/index.html/3rd',
				'lst'  => '2022-12-22',
				'cf'   => 'never',
				'prio' => 0.8,
				'pid'  => 228,
			),
			array(
				'url'  => 'http://www.foo.com/index.html/4th',
				'lst'  => '2022-12-22',
				'cf'   => 'yearly',
				'prio' => 0.1,
				'pid'  => 227,
			),
		);
		self::$gsg->set_pages( $test_pages );
		self::$gsg->save_pages();
	}
}
OldVersion::test();
