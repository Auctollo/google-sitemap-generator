<?php
/**
 * $Id: sitemap.php 2947864 2023-08-04 18:02:26Z auctollo $

 *  XML Sitemap Generator for Google
 * ==============================================================================

 * This generator will create a sitemaps.org compliant sitemap of your WordPress site.

 * For additional details like installation instructions, please check the readme.txt and documentation.txt files.

 * Have fun!

 * Info for WordPress:
 * ==============================================================================
 * Plugin Name: XML Sitemap Generator for Google
 * Plugin URI: https://auctollo.com/
 * Description: This plugin improves SEO using sitemaps for best indexation by search engines like Google, Bing, Yahoo and others.
 * Version: 4.1.21
 * Author: Auctollo
 * Author URI: https://auctollo.com/
 * Text Domain: google-sitemap-generator
 * Domain Path: /lang


 * Copyright 2019 - 2024 AUCTOLLO
 * Copyright 2005 - 2018 ARNE BRACHHOLD

 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.

 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @author AUCTOLLO
 * @package sitemap
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

 * Please see license.txt for the full license.
 */

global $wp_version;
if ( (int) $wp_version > 4 ) {
	include_once( ABSPATH . 'wp-admin/includes/plugin-install.php' ); //for plugins_api..
}

require_once trailingslashit( dirname( __FILE__ ) ) . 'sitemap-core.php';
require_once trailingslashit( dirname( __FILE__ ) ) . 'class-googlesitemapgeneratorindexnow.php'; //add class indexNow file

include_once( ABSPATH . 'wp-admin/includes/file.php' );
include_once( ABSPATH . 'wp-admin/includes/misc.php' );
include_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );

define( 'SM_SUPPORTFEED_URL', 'https://wordpress.org/support/plugin/google-sitemap-generator/feed/' );
define( 'SM_BETA_USER_INFO_URL', 'https://api.auctollo.com/beta/consent' );
define( 'SM_LEARN_MORE_API_URL', 'https://api.auctollo.com/lp' );
define( 'SM_BANNER_HIDE_DURATION_IN_DAYS', 7 );
define( 'SM_CONFLICT_PLUGIN_LIST', 'All in One SEO,Yoast SEO, Jetpack, Wordpress Sitemap' );
add_action( 'admin_init', 'register_consent', 1 );
add_action( 'admin_head', 'ga_header' );
add_action( 'admin_footer', 'ga_footer' );
add_action( 'plugins_loaded', function() {
	load_plugin_textdomain( 'google-sitemap-generator', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );


});

add_action( 'transition_post_status', 'indexnow_after_post_save', 10, 3 ); //send to indexNow

add_action('wpmu_new_blog', 'disable_conflict_sitemaps_on_new_blog', 10, 6);

function disable_conflict_sitemaps_on_new_blog($blog_id, $user_id, $domain, $path, $site_id, $meta) {
    switch_to_blog($blog_id);
    $aioseo_option_key = 'aioseo_options';
    if (get_option($aioseo_option_key) !== null) {
        $aioseo_options = get_option($aioseo_option_key);
        $aioseo_options = json_decode($aioseo_options, true);
        $aioseo_options['sitemap']['general']['enable'] = false;
        update_option($aioseo_option_key, json_encode($aioseo_options));
    }
    restore_current_blog();
}

add_action('parse_request', 'plugin_check_sitemap_request');
function plugin_check_sitemap_request($wp) {
	if(is_multisite()) {
		if(isset(get_blog_option( get_current_blog_id(), 'sm_options' )['sm_b_sitemap_name'])) {
			$sm_sitemap_name = get_blog_option( get_current_blog_id(), 'sm_options' )['sm_b_sitemap_name'];
		}
	} else if(isset(get_option('sm_options')['sm_b_sitemap_name'])) $sm_sitemap_name = get_option('sm_options')['sm_b_sitemap_name'];
	
    if(isset($wp->request) && $wp->request === 'wp-sitemap.xml' && $sm_sitemap_name !== 'sitemap') {
        status_header(404);
        nocache_headers();
        include( get_query_template( '404' ) );
        exit;
    }
}

/**
 * Google analytics .
 */
function ga_header() {
	if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		global $wp_version;
		$window_url   = 'http://' . $_SERVER[ 'HTTP_HOST' ] . $_SERVER[ 'REQUEST_URI' ];
		$parts        = wp_parse_url( $window_url );
		$current_page = '';

		$window_url   = home_url() . $_SERVER[ 'REQUEST_URI' ];
		$parts        = wp_parse_url( $window_url );
		$current_page = '';
		$current_url  = $_SERVER['REQUEST_URI'];
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			if ( isset( $query['page'] ) ) {
				$current_page = $query['page'];
			}
		}
		$plugin_version = GoogleSitemapGeneratorLoader::get_version();

		$consent_value = get_option( 'sm_user_consent' );

		echo "<script>
		setTimeout(()=>{

			var user_consent = document.getElementById('user_consent')
			if(user_consent){
				user_consent.addEventListener('click',function(){
					setTimeout(()=>{
						window.location.reload()
					},1000)
				})
			}
			var enable_updates = document.querySelector(\"[name='enable_auto_update']\")
			if(enable_updates){
				enable_updates.addEventListener('click', function (event) {
					event.preventDefault();
					document.getElementById('enable_updates').value = \"true\";
					document.querySelector(\"[id='enable-updates-form']\").submit();
				});
			}
			var do_not_enable_updates = document.querySelector(\"[name='do_not_enable_auto_update']\")
			if(do_not_enable_updates){
				do_not_enable_updates.addEventListener('click', function (event) {
					event.preventDefault();
					document.getElementById('enable_updates').value = \"false\";
					document.querySelector(\"[id='enable-updates-form']\").submit();
				});
			}

			var conflict_plugin = document.querySelectorAll('.conflict_plugin')
			conflict_plugin.forEach((plugin,index)=>{
				plugin.addEventListener('click', function (event) {
					event.preventDefault();
					console.log(plugin)
					document.getElementById('disable_plugin').value = plugin.id;
					document.querySelector(\"[id='disable-plugins-form']\").submit();
				});
			})

			var more_info_button = document.getElementById('more_info_button')
			if(more_info_button){
				more_info_button.addEventListener('click',function(){
					document.getElementById('cookie-info-banner-wrapper').style.display = 'flex'
				})
			}
			var close_cookie_info = document.getElementById('close_popup')
			if(close_cookie_info){
				close_cookie_info.addEventListener('click',function(){
				document.getElementById('cookie-info-banner-wrapper').style.display = 'none'

				})
			}
		},2000);

		</script>";
		if ( 'yes' === $consent_value && 'google-sitemap-generator/sitemap.php' === $current_page ) {
			echo "			
			<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
			new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
			j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
			'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
			})(window,document,'script','dataLayer','GTM-N8CQCXB');</script>
				";
		}
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			if ( isset( $query['page'] ) ) {
				$current_page = $query['page'];
				if ( strpos( $current_page, 'google-sitemap-generator' ) !== false ) {
						echo "
						<script>
							setTimeout(()=>{

							if(document.getElementById('discard_content')){
								document.getElementById('discard_content').classList.remove('discard_button_outside_settings')
								document.getElementById('discard_content').classList.add('discard_button')
							}
							if( document.getElementById(\"user-consent-form\") ){
								const form = document.getElementById(\"user-consent-form\")
								var plugin_version = document.createElement(\"input\")
								plugin_version.name = \"plugin_version\"
								plugin_version.id = \"plugin_version\"
								plugin_version.value = \"<?php echo $wp_version;?>\"
								plugin_version.type = \"hidden\"
								form.appendChild(plugin_version)
								var wordpress_version = document.createElement(\"input\")
								wordpress_version.name = \"wordpress_version\"
								wordpress_version.id = \"wordpress_version\"
								wordpress_version.value = '$wp_version'
								wordpress_version.type = \"hidden\"
								form.appendChild(wordpress_version)
							}

							},200);
						</script>";
				} else {
					echo '<script>
					setTimeout(()=>{
						let discardContent = document.getElementById("discard_content");
						
						if (discardContent) {
							discardContent.classList.add("discard_button_outside_settings");
							discardContent.classList.remove("discard_button");
						}
					}, 200);
					</script>';
				}
			} else {
				echo '<script>
				setTimeout(()=>{
					let discardContent = document.getElementById("discard_content");
					
					if (discardContent) {
						discardContent.classList.add("discard_button_outside_settings");
						discardContent.classList.remove("discard_button");
					}
				}, 200);
				</script>';
			}
		} else {
			echo "<script>
			setTimeout(()=>{
				let discardContent = document.getElementById(\"discard_content\");
				if (discardContent) {
					document.getElementById(\"discard_content\").classList.add(\"discard_button_outside_settings\")
					document.getElementById(\"discard_content\").classList.remove(\"discard_button\")
				}
				if( document.getElementById(\"user-consent-form\") ){
					const form = document.getElementById(\"user-consent-form\")
					var plugin_version = document.createElement(\"input\")
					plugin_version.name = \"plugin_version\"
					plugin_version.id = \"plugin_version\"
					plugin_version.value = '$plugin_version'
					plugin_version.type = \"hidden\"
					form.appendChild(plugin_version)

					var wordpress_version = document.createElement(\"input\")
					wordpress_version.name = \"wp_version\"
					wordpress_version.id = \"wp_version\"
					wordpress_version.value = '$wp_version'
					wordpress_version.type = \"hidden\"
					form.appendChild(wordpress_version)
				}
			},200);
				</script>";
			return;
		}
	}
}

/**
 * Google analytics .
 */
function ga_footer() {
	if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		$banner_discarded_count = get_option( 'sm_beta_banner_discarded_count' );
		if ( 1 === $banner_discarded_count || '1' === $banner_discarded_count ) {
			echo '<script>
			if(document.getElementById("discard_content")){
				document.getElementById("discard_content").classList.add("reject_consent")
				document.getElementById("discard_content").classList.remove("discard_button")
			}
			</script>';
		}
	}
}

/**
 * Check if the requirements of the sitemap plugin are met and loads the actual loader
 *
 * @package sitemap
 * @since 4.0
 */
function sm_setup() {

	$fail = false;

	// Check minimum PHP requirements, which is 5.2 at the moment.
	if ( version_compare( PHP_VERSION, '5.2', '<' ) ) {
		add_action( 'admin_notices', 'sm_add_php_version_error' );
		$fail = true;
	}

	// Check minimum WP requirements, which is 3.3 at the moment.
	if ( version_compare( $GLOBALS['wp_version'], '3.3', '<' ) ) {
		add_action( 'admin_notices', 'sm_add_wp_version_error' );
		$fail = true;
	}

	if ( ! $fail ) {
		require_once trailingslashit( dirname( __FILE__ ) ) . 'class-googlesitemapgeneratorloader.php';
	}

}

/**
 * Adds a notice to the admin interface that the WordPress version is too old for the plugin
 *
 * @package sitemap
 * @since 4.0
 */
function sm_add_wp_version_error() {
	/* translators: %s: search term */

	echo '<div id=\'sm-version-error\' class=\'error fade\'><p><strong>' . esc_html( __( 'Your WordPress version is too old for XML Sitemaps.', 'google-sitemap-generator' ) ) . '</strong><br /> ' . esc_html( sprintf( __( 'Unfortunately this release of Google XML Sitemaps requires at least WordPress %4$s. You are using WordPress %2$s, which is out-dated and insecure. Please upgrade or go to <a href=\'%1$s\'>active plugins</a> and deactivate the Google XML Sitemaps plugin to hide this message. You can download an older version of this plugin from the <a href=\'%3$s\'>plugin website</a>.', 'google-sitemap-generator' ), 'plugins.php?plugin_status=active', esc_html( $GLOBALS['wp_version'] ), 'http://www.arnebrachhold.de/redir/sitemap-home/', '3.3' ) ) . '</p></div>';
}

/**
 * Adds a notice to the admin interface that the WordPress version is too old for the plugin
 *
 * @package sitemap
 * @since 4.0
 */
function sm_add_php_version_error() {
	/* translators: %s: search term */

	echo '<div id=\'sm-version-error\' class=\'error fade\'><p><strong>' . esc_html( __( 'Your PHP version is too old for XML Sitemaps.', 'google-sitemap-generator' ) ) . '</strong><br /> ' . esc_html( sprintf( __( 'Unfortunately this release of Google XML Sitemaps requires at least PHP %4$s. You are using PHP %2$s, which is out-dated and insecure. Please ask your web host to update your PHP installation or go to <a href=\'%1$s\'>active plugins</a> and deactivate the Google XML Sitemaps plugin to hide this message. You can download an older version of this plugin from the <a href=\'%3$s\'>plugin website</a>.', 'google-sitemap-generator' ), 'plugins.php?plugin_status=active', PHP_VERSION, 'http://www.arnebrachhold.de/redir/sitemap-home/', '5.2' ) ) . '</p></div>';
}

/**
 * Returns the file used to load the sitemap plugin
 *
 * @package sitemap
 * @since 4.0
 * @return string The path and file of the sitemap plugin entry point
 */
function sm_get_init_file() {
	return __FILE__;
}

/**
 * Register beta user consent function.
 */
function register_consent() {
	if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			if ( isset( $_POST['user_consent_yes'] ) ) {
				if (isset($_POST['user_consent_yesno_nonce_token']) && check_admin_referer('user_consent_yesno_nonce', 'user_consent_yesno_nonce_token')){
					update_option( 'sm_user_consent', 'yes' );
				}
			}
			if ( isset( $_POST['user_consent_no'] ) ) {
				if (isset($_POST['user_consent_yesno_nonce_token']) && check_admin_referer('user_consent_yesno_nonce', 'user_consent_yesno_nonce_token')){
					update_option( 'sm_user_consent', 'no' );
				}
			}
			if ( isset( $_GET['action'] ) ) {
				if ( 'no' === $_GET['action'] ) {
					if ( $_SERVER['QUERY_STRING'] ) {
						if( strpos( $_SERVER['QUERY_STRING'], 'google-sitemap-generator' ) ) {
							update_option( 'sm_show_beta_banner', 'false' );
							$count = get_option( 'sm_beta_banner_discarded_count' );
							if ( gettype( $count ) !== 'boolean' ) {
								update_option( 'sm_beta_banner_discarded_count', (int) $count + 1 );
							} else {
								add_option( 'sm_beta_banner_discarded_on', gmdate( 'Y/m/d' ) );
								update_option( 'sm_beta_banner_discarded_count', (int) 1 );
							}
							GoogleSitemapGeneratorLoader::setup_rewrite_hooks();
							GoogleSitemapGeneratorLoader::activate_rewrite();
						} else {
							add_option( 'sm_beta_notice_dismissed_from_wp_admin', 'true' );
						}
					} else {
						add_option( 'sm_beta_notice_dismissed_from_wp_admin', 'true' );
					}
				}
			}
			if ( isset( $_POST['enable_updates'] ) ) {
				if (isset($_POST['enable_updates_nonce_token']) && check_admin_referer('enable_updates_nonce', 'enable_updates_nonce_token')){
					if ( 'true' === $_POST['enable_updates'] ) {
						$auto_update_plugins = get_option( 'auto_update_plugins' );
						if ( ! is_array( $auto_update_plugins ) ) {
							$auto_update_plugins = array();
						}
						array_push( $auto_update_plugins, 'google-sitemap-generator/sitemap.php' );
						update_option( 'auto_update_plugins', $auto_update_plugins );
					} elseif ( 'false' === $_POST['enable_updates'] ) {
						update_option( 'sm_hide_auto_update_banner', 'yes' );
					}
				}
			}
			
			/*
			if ( isset( $_POST['disable_plugin'] ) ) {
				if (isset($_POST['disable_plugin_sitemap_nonce_token']) && check_admin_referer('disable_plugin_sitemap_nonce', 'disable_plugin_sitemap_nonce_token')){
					if ( strpos( $_POST['disable_plugin'], 'all_in_one' ) !== false  ) {
						$default_value   = 'default';
						$aio_seo_options = get_option( 'aioseo_options', $default_value );
						if ( $aio_seo_options !== $default_value ) {
							$aio_seo_options                           = json_decode( $aio_seo_options );
							$aio_seo_options->sitemap->general->enable = 0;
							update_option( 'aioseo_options', json_encode( $aio_seo_options ) );
						}
					} elseif( strpos( $_POST['disable_plugin'], 'wp-seo' ) !== false ) { 
						$yoast_options = get_option( 'wpseo' );
						$yoast_options['enable_xml_sitemap'] = false;
						update_option( 'wpseo', $yoast_options );
					}
				}
			}
			*/
		}
	}
	$updateUrlRules = get_option('sm_options');
	if(!isset($updateUrlRules['sm_b_rewrites2']) || $updateUrlRules['sm_b_rewrites2'] == false){
		GoogleSitemapGeneratorLoader::setup_rewrite_hooks();
		GoogleSitemapGeneratorLoader::activate_rewrite();
		GoogleSitemapGeneratorLoader::activation_indexnow_setup();

		if (isset($updateUrlRules['sm_b_rewrites2'])) {
			$updateUrlRules['sm_b_rewrites2'] = true;
			update_option('sm_options', $updateUrlRules);
		} else {
			$updateUrlRules['sm_b_rewrites2'] = true;
			add_option('sm_options', $updateUrlRules);
			update_option('sm_options', $updateUrlRules);
		}
		
	}
	if(isset($updateUrlRules['sm_links_page'] )){
		$sm_links_page = intval($updateUrlRules['sm_links_page']);
		if($sm_links_page < 1000) {
			$updateUrlRules['sm_links_page'] = 1000;
			update_option('sm_options', $updateUrlRules);
		}
	}

	if(!isset($updateUrlRules['sm_b_activate_indexnow']) || $updateUrlRules['sm_b_activate_indexnow'] == false){
		$updateUrlRules['sm_b_activate_indexnow'] = true;
		$updateUrlRules['sm_b_indexnow'] = true;
		update_option('sm_options', $updateUrlRules);
	}
	
}

function disable_plugins_callback(){
    if (current_user_can('manage_options')) {
        check_ajax_referer('disable_plugin_sitemap_nonce', 'nonce');

        $pluginList = sanitize_text_field($_POST['pluginList']);
        $pluginsToDisable = explode(',', $pluginList);

        foreach ($pluginsToDisable as $plugin) {
            if ($plugin === 'all-in-one-seo-pack/all_in_one_seo_pack.php') {
                /* all in one seo deactivation */
                $aioseo_option_key = 'aioseo_options';
                if ($aioseo_options = get_option($aioseo_option_key)) {
                    $aioseo_options = json_decode($aioseo_options, true);
                    $aioseo_options['sitemap']['general']['enable'] = false;
                    update_option($aioseo_option_key, json_encode($aioseo_options));
                }
            }
            if ($plugin === 'wordpress-seo/wp-seo.php') {
                /* yoast sitemap deactivation */
                if ($yoast_options = get_option('wpseo')) {
                    $yoast_options['enable_xml_sitemap'] = false;
                    update_option('wpseo', $yoast_options);
                }
            }
			if ($plugin === 'jetpack/jetpack.php') {
                /* jetpack sitemap deactivation */
                $modules_array = get_option('jetpack_active_modules');
				if(is_array($modules_array)) {
					if (in_array('sitemaps', $modules_array)) {
						$key = array_search('sitemaps', $modules_array);
						unset($modules_array[$key]);
						update_option('jetpack_active_modules', $modules_array);
					}
				}
            }
			if ($plugin === 'wordpress-sitemap') {
                /* Wordpress sitemap deactivation */
                $options = get_option('sm_options', array());
				if (isset($options['sm_wp_sitemap_status'])) $options['sm_wp_sitemap_status'] = false;
				else $options['sm_wp_sitemap_status'] = false;
				update_option('sm_options', $options);
            }
        }

        echo 'Plugins sitemaps disabled successfully';
        wp_die();
    }
}

function conflict_plugins_admin_notice(){
	GoogleSitemapGeneratorLoader::create_notice_conflict_plugin();
}

 /* send to index updated url */
function indexnow_after_post_save($new_status, $old_status, $post) {
	$indexnow = get_option('sm_options');
	$indexNowStatus = isset($indexnow['sm_b_indexnow']) ? $indexnow['sm_b_indexnow'] : false;
	if ($indexNowStatus === true) {
	    $newUrlToIndex = new GoogleSitemapGeneratorIndexNow();
		$is_changed = false;
		$type = "add";
		if ($old_status === 'publish' && $new_status === 'publish') {
			$is_changed = true;
			$type = "update";
		}
		else if ($old_status != 'publish' && $new_status === 'publish') {
			$is_changed = true;
			$type = "add";
		}
		else if ($old_status === 'publish' && $new_status === 'trash') {
			$is_changed = true;
			$type = "delete";
		}
		if ($is_changed) $newUrlToIndex->start(get_permalink($post));
    }
}

// Don't do anything if this file was called directly.
if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) && ! class_exists( 'GoogleSitemapGeneratorLoader', false ) ) {
	sm_setup();
	
	if(isset(get_option('sm_options')['sm_wp_sitemap_status']) ) $wp_sitemap_status = get_option('sm_options')['sm_wp_sitemap_status'];
	else $wp_sitemap_status = true;
	if($wp_sitemap_status = true) $wp_sitemap_status = '__return_true';
	else $wp_sitemap_status = '__return_false';
	add_filter( 'wp_sitemaps_enabled', $wp_sitemap_status );
	
	add_action('wp_ajax_disable_plugins', 'disable_plugins_callback');

	add_action('admin_notices', 'conflict_plugins_admin_notice');

}
