<?php
require dirname( dirname( __FILE__ ) ) . '/sitemap-core.php';
require_once '/var/www/html/wordpress-5.8.1/wordpress/wp-load.php';
/**
 * Test case for create page .
 */
class NewVersion {
	/**
	 * Test function .
	 */
	public static function test() {
		self::test_get_pages();
	}
	/**
	 * GoogleSitemapGenerator variable
	 *
	 * @var GoogleSitemapGenerator .
	 */
	public static $gsg;
	/**
	 * Get pages .
	 */
	public static function test_get_pages() {
		self::$gsg = new GoogleSitemapGenerator();
		self::$gsg->initate();
		$pages = self::$gsg->get_pages();
		print_r( $pages );
	}
}
NewVersion::test();
