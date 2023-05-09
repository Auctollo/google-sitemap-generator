<?php
/**
 * Loader class for the XML Sitemap Generator
 *
 * This class takes care of the sitemap plugin and tries to load the different parts as late as possible.
 * On normal requests, only this small class is loaded. When the sitemap needs to be rebuild, the generator itself is loaded.
 * The last stage is the user interface which is loaded when the administration page is requested.
 *
 * @author Arne Brachhold
 * @package sitemap
 */
require_once trailingslashit( dirname( __FILE__ ) ) . 'class-googlesitemapgeneratorui.php';

/**
 * This class is for the sitemap loader
 */
class GoogleSitemapGeneratorLoader {

	/**
	 * Version of the generator in SVN.
	 *
	 * @var string Version of the generator in SVN
	 */
	private static $svn_version = '$Id: class-googlesitemapgeneratorloader.php 937300 2014-06-23 18:04:11Z arnee $';


	/**
	 * Enabled the sitemap plugin with registering all required hooks
	 *
	 * @uses add_action  Adds actions for admin menu, executing pings and handling robots.t xt
	 * @uses add_filter Adds filtes for admin menu icon and contexual help
	 * @uses GoogleSitemapGeneratorLoader::call_show_ping_result() Shows the ping result on request
	 */
	public static function enable() {

		// Register the sitemap creator to WordPress...
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );

		// Add a widget to the dashboard.
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'wp_dashboard_setup' ) );

		// Nice icon for Admin Menu (requires Ozh Admin Drop Down Plugin) .
		add_filter( 'ozh_adminmenu_icon', array( __CLASS__, 'register_admin_icon' ) );

		// Additional links on the plugin page .
		add_filter( 'plugin_row_meta', array( __CLASS__, 'register_plugin_links' ), 10, 2 );

		// Listen to ping request .
		add_action( 'sm_ping', array( __CLASS__, 'call_send_ping' ), 10, 1 );

		// Listen to daily ping .
		add_action( 'sm_ping_daily', array( __CLASS__, 'call_send_ping_daily' ), 10, 1 );

		// Post is somehow changed (also publish to publish (=edit) is fired) .
		add_action( 'transition_post_status', array( __CLASS__, 'schedule_ping_on_status_change' ), 9999, 3 );

		add_action(
			'init',
			function() {
				remove_action( 'init', 'wp_sitemaps_get_server' );
			},
			5
		);

		// Robots.txt request .
		add_action( 'do_robots', array( __CLASS__, 'call_do_robots' ), 100, 0 );

		// Help topics for context sensitive help .
		// add_filter('contextual_help_list', array( __CLASS__, 'call_html_show_help_list' ), 9999, 2); .

		// Check if the result of a ping request should be shown .
		if ( isset( $_GET['sm_ping_service'] ) && ! empty( sanitize_text_field( wp_unslash( $_GET['sm_ping_service'] ) ) ) ) {
			self::call_show_ping_result();
		}

		// Fix rewrite rules if not already done on activation hook. This happens on network activation for example.
		if ( get_option( 'sm_rewrite_done', null ) !== self::$svn_version ) {
			add_action( 'wp_loaded', array( __CLASS__, 'activate_rewrite' ), 9999, 1 );
		}

		// Schedule daily ping .
		if ( ! wp_get_schedule( 'sm_ping_daily' ) ) {
			wp_schedule_event( time() + ( 60 * 60 ), 'daily', 'sm_ping_daily' );
		}

		// Disable the WP core XML sitemaps .
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
	}

	/**
	 * Sets up the query vars and template redirect hooks
	 *
	 * @uses GoogleSitemapGeneratorLoader::register_query_vars
	 * @uses GoogleSitemapGeneratorLoader::do_template_redirect
	 * @since 4.0
	 */
	public static function setup_query_vars() {

		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ), 1, 1 );

		add_filter( 'template_redirect', array( __CLASS__, 'do_template_redirect' ), 1, 0 );

	}

	/**
	 * Register the plugin specific 'xml_sitemap' query var
	 *
	 * @since 4.0
	 * @param array $vars Array Array of existing query_vars .
	 * @return Array An aarray containing the new query vars
	 */
	public static function register_query_vars( $vars ) {
		array_push( $vars, 'xml_sitemap' );
		return $vars;
	}

	/**
	 * Registers the plugin specific rewrite rules
	 *
	 * Combined: sitemap(-+([a-zA-Z0-9_-]+))?\.(xml|html)(.gz)?$
	 *
	 * @since 4.0
	 * @param array $wp_rules Array of existing rewrite rules .
	 * @return Array An array containing the new rewrite rules
	 */
	public static function add_rewrite_rules( $wp_rules ) {
		$sm_rules = array(
			'sitemap(-+([a-zA-Z0-9_-]+))?\.xml$'     => 'index.php?xml_sitemap=params=$matches[2]',
			'sitemap(-+([a-zA-Z0-9_-]+))?\.xml\.gz$' => 'index.php?xml_sitemap=params=$matches[2];zip=true',
			'sitemap(-+([a-zA-Z0-9_-]+))?\.html$'    => 'index.php?xml_sitemap=params=$matches[2];html=true',
			'sitemap(-+([a-zA-Z0-9_-]+))?\.html.gz$' => 'index.php?xml_sitemap=params=$matches[2];html=true;zip=true',
		);
		return array_merge( $sm_rules, $wp_rules );
	}

	/**
	 * Returns the rules required for Nginx permalinks
	 *
	 * @return string[]
	 */
	public static function get_ngin_x_rules() {
		return array(
			'rewrite ^/sitemap(-+([a-zA-Z0-9_-]+))?\.xml$ "/index.php?xml_sitemap=params=$2" last;',
			'rewrite ^/sitemap(-+([a-zA-Z0-9_-]+))?\.xml\.gz$ "/index.php?xml_sitemap=params=$2;zip=true" last;',
			'rewrite ^/sitemap(-+([a-zA-Z0-9_-]+))?\.html$ "/index.php?xml_sitemap=params=$2;html=true" last;',
			'rewrite ^/sitemap(-+([a-zA-Z0-9_-]+))?\.html.gz$ "/index.php?xml_sitemap=params=$2;html=true;zip=true" last;',
		);

	}

	/**
	 * Adds the filters for wp rewrite rule adding
	 *
	 * @since 4.0
	 * @uses add_filter()
	 */
	public static function setup_rewrite_hooks() {
		add_filter( 'rewrite_rules_array', array( __CLASS__, 'add_rewrite_rules' ), 1, 1 );
	}

	/**
	 * Deregisters the plugin specific rewrite rules
	 *
	 * Combined: sitemap(-+([a-zA-Z0-9_-]+))?\.(xml|html)(.gz)?$
	 *
	 * @since 4.0
	 * @param array $wp_rules Array of existing rewrite rules .
	 * @return Array An array containing the new rewrite rules
	 */
	public static function remove_rewrite_rules( $wp_rules ) {
		$sm_rules = array(
			'sitemap(-+([a-zA-Z0-9_-]+))?\.xml$'     => 'index.php?xml_sitemap=params=$matches[2]',
			'sitemap(-+([a-zA-Z0-9_-]+))?\.xml\.gz$' => 'index.php?xml_sitemap=params=$matches[2];zip=true',
			'sitemap(-+([a-zA-Z0-9_-]+))?\.html$'    => 'index.php?xml_sitemap=params=$matches[2];html=true',
			'sitemap(-+([a-zA-Z0-9_-]+))?\.html.gz$' => 'index.php?xml_sitemap=params=$matches[2];html=true;zip=true',
		);
		foreach ( $wp_rules as $key => $value ) {
			if ( array_key_exists( $key, $sm_rules ) ) {
				unset( $wp_rules[ $key ] );
			}
		}
		return $wp_rules;
	}

	/**
	 * Remove rewrite hooks method
	 */
	public static function remove_rewrite_hooks() {
		add_filter( 'rewrite_rules_array', array( __CLASS__, 'remove_rewrite_rules' ), 1, 1 );
	}

	/**
	 * Flushes the rewrite rules
	 *
	 * @since 4.0
	 * @global $wp_rewrite WP_Rewrite
	 * @uses WP_Rewrite::flush_rules()
	 */
	public static function activate_rewrite() {
		// @var $wp_rewrite WP_Rewrite .
		global $wp_rewrite;
		$wp_rewrite->flush_rules( false );
		update_option( 'sm_rewrite_done', self::$svn_version );
	}

	/**
	 * Handled the plugin activation on installation
	 *
	 * @uses GoogleSitemapGeneratorLoader::activate_rewrite
	 * @since 4.0
	 */
	public static function activate_plugin() {
		self::setup_rewrite_hooks();
		self::activate_rewrite();

		if ( self::load_plugin() ) {
			$gsg = GoogleSitemapGenerator::get_instance();
			if ( $gsg->old_file_exists() ) {
				$gsg->delete_old_files();
			}
		}

	}

	/**
	 * Handled the plugin deactivation
	 *
	 * @uses GoogleSitemapGeneratorLoader::activate_rewrite
	 * @since 4.0
	 */
	public static function deactivate_plugin() {
		global $wp_rewrite;
		delete_option( 'sm_rewrite_done' );
		wp_clear_scheduled_hook( 'sm_ping_daily' );
		self::remove_rewrite_hooks();
		$wp_rewrite->flush_rules( false );
	}


	/**
	 * Handles the plugin output on template redirection if the xml_sitemap query var is present.
	 *
	 * @since 4.0
	 */
	public static function do_template_redirect() {
		// @var $wp_query WP_Query .
		global $wp_query;
		if ( ! empty( $wp_query->query_vars['xml_sitemap'] ) ) {
			$wp_query->is_404  = false;
			$wp_query->is_feed = true;
			self::call_show_sitemap( $wp_query->query_vars['xml_sitemap'] );
		}
	}

	/**
	 * Registers the plugin in the admin menu system
	 *
	 * @uses add_options_page()
	 */
	public static function register_admin_page() {
		add_options_page( __( 'XML-Sitemap Generator', 'sitemap' ), __( 'XML-Sitemap', 'sitemap' ), 'administrator', self::get_base_name(), array( __CLASS__, 'call_html_show_options_page' ) );
	}

	/**
	 * Add a widget to the dashboard.
	 *
	 * @param string $a .
	 */
	public static function wp_dashboard_setup( $a ) {
		self::load_plugin();
		$sg = GoogleSitemapGenerator::get_instance();

		if ( $sg->show_survey() ) {
			add_action( 'admin_notices', array( __CLASS__, 'wp_dashboard_admin_notices' ) );
		}
	}
	/**
	 * Wp dashboard admin notices method
	 */
	public static function wp_dashboard_admin_notices() {
		$sg = GoogleSitemapGenerator::get_instance();
		$sg->html_survey();
	}
	/**
	 * Hide banner info.
	 */
	public function hide_banner() {
		update_option( 'sm_show_beta_banner', 'false' );
		add_option( 'sm_beta_banner_discarded_on', gmdate( 'Y/m/d' ) );
		update_option( 'sm_beta_banner_discarded_count', (int) 2 );
	}
	/**
	 * Beta notice.
	 */
	public static function beta_notice() {
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
		$arr = array(
			'br'     => array(),
			'p'      => array(),
			'h3'     => array(),
			'div'    => array(
				'style' => array(
					'display'         => 'flex',
					'justify-content' => 'space-between',
				),
				'class' => array(),
				'id'    => array(),
			),
			'a'      => array(
				'href'  => array(),
				'name'  => array(),
				'class' => array(),
				'name'  => array(),
				'id'    => array(),
			),
			'h4'     => array(
				'style' => array(
					'width'   => array(),
					'display' => array(),
				),
				'id'    => array(),
				'class' => array(),
			),
			'h3'     => array(
				'style' => array(
					'width'   => array(),
					'display' => array(),
				),
				'id'    => array(),
			),
			'img'    => array(
				'src'   => array(),
				'class' => array(),
				'id'    => array(),
			),
			'button' => array(
				'onClick' => array(),
				'type'    => array(),
				'onclick' => array(),
				'class'   => array(),
				'id'      => array(),
			),
			'strong' => array(),
			'input'  => array(
				'type'  => array(),
				'class' => array(),
				'id'    => array(),
				'name'  => array(),
				'value' => array(),
				'style' => array(
					'position'     => array(),
					'padding'      => array(),
					'background'   => array(),
					'right'        => array(),
					'color'        => array(),
					'border-color' => array(),
					'cursor'       => array(),
				),
			),
			'form'   => array(
				'method' => array(),
				'action' => array(),
				'style'  => array(
					'margin-top'  => array(),
					'margin-left' => array(),
					'display'     => array(),
				),
			),
		);
		$default_value    = 'show_banner';
		$value            = get_option( 'sm_show_beta_banner', $default_value );
		$now              = time();
		$banner_discarded = strtotime( get_option( 'sm_beta_banner_discarded_on' ) );
		$image_url        = trailingslashit( plugins_url( '', __FILE__ ) ) . 'img/close.jpg';

		$page_to_show_notice    = array( 'settings_page_google-sitemap-generator/sitemap', 'dashboard', 'plugins' );
		$current_screen         = get_current_screen()->base;
		$banner_discarded_count = get_option( 'sm_beta_banner_discarded_count' );
		if ( gettype( $banner_discarded ) === 'boolean' ) {
			$banner_discarded = time();
		}
		$datediff = $now - $banner_discarded;
		$datediff = round( $datediff / ( 60 * 60 * 24 ) );
		if ( ( in_array( $current_screen, $page_to_show_notice, true ) ) && ( $value === $default_value || 'true' === $value ) && ( 'true' !== get_option( 'sm_beta_notice_dismissed_from_wp_admin' ) || 'google-sitemap-generator/sitemap.php' === $current_page ) || ( 'google-sitemap-generator/sitemap.php' === $current_page && $datediff >= SM_BANNER_HIDE_DURATION_IN_DAYS && $banner_discarded_count < 2 ) ) {
			?>
			<style>
				.justify-content{
					display: flex;
					justify-content: space-between;
					align-items: center;
				}
				a.discard_button, a.discard_button_outside_settings{
					border-radius: 50%;
					border: 0px;
					text-align: center;
					justify-content: center;
					align-items: center;
					margin-left: 40px;
					margin-right: 5px;
					cursor: pointer;
					height: 20px;
					background-color: #787c82;
					color: white;
					font-size: small;
					font-weight: bold;
					width: 20px;
					padding-bottom: 0px;
					text-decoration: none;
				}
				.reject_consent{
					border-radius: 50%;
					border: 0px;
					text-align: center;
					justify-content: center;
					align-items: center;
					margin-left: 40px;
					margin-right: 5px;
					cursor: pointer;
					height: 20px;
					background-color: #787c82;
					color: white;
					font-size: small;
					font-weight: bold;
					width: 20px;
				}
				.cookie-info-banner-wrapper {
					position: fixed;
					z-index: 100;
					left: 0;
					top: 0;
					width: 100%;
					height: 100%;
					background-color: rgba(0, 0, 0, 0.5);
					opacity: 1;
					display: none;
					transform: scale(1.0);
					transition: visibility 0s linear 0s, opacity 0.25s 0s, transform 0.25s;
				}
				.modal-wrapper {
					position: fixed;
					z-index: 100;
					left: 0;
					top: 0;
					width: 100%;
					height: 100%;
					background-color: rgba(0, 0, 0, 0.5);
					opacity: 1;
					visibility: visible;
					transform: scale(1.0);
					transition: visibility 0s linear 0s, opacity 0.25s 0s, transform 0.25s;
				}

				.modal-container {
				position: absolute;
				top: 50%;
				left: 50%;
				transform: translate(-50%, -50%);
				background-color: white;
				padding: 1rem 1.5rem;
				width: 35rem;
				border-radius: 0.5rem;
				z-index: 100;
				}
				.allow_consent {
					color: #ffffff;
					border-color: #ffffff;
					background-color: #008078;
					margin-right: 1em;
					min-width: 100px;
					height: auto;
					white-space: normal;
					word-break: break-word;
					word-wrap: break-word;
					padding: 12px 10px;
					cursor: pointer;
				}
				.decline_consent {
					background-color: #fff;
					border-color:  #ef4056 ;
					color:  #ef4056 ;
					text-decoration: none;
					min-width: 100px;
					height: auto;
					white-space: normal;
					word-break: break-word;
					word-wrap: break-word;
					padding: 12px 10px;
					cursor: pointer;
				}
				#close_popup {
					border: none;
					height: 20px;
					width: 25px;
					padding: 0px;
					position: absolute;
					right: 10px;
					background-image: url( <?php echo $image_url; ?> );
				}
				.close_cookie_information{
					height: 20px;
					width: 25px;
				}
				a.allow_beta_consent {
					background: #2271b1;
					color: white;
					border-color: #2271b1;
					cursor: pointer;
					padding: 8px;
					text-decoration: none;
				}
				.allow_beta_consent:hover{
					color: white;
					outline: 1px solid #2271b1;
				}
				button.allow_beta_consent{
					border: none;
				}
		</style>
		<div class="updated notice" style="display: flex;justify-content:space-between;">
				<?php
				$arr = array(
					'br'     => array(),
					'p'      => array(),
					'div'    => array(
						'style' => array(
							'display'         => 'flex',
							'justify-content' => 'space-between',
						),
						'class' => array(),
						'id'    => array(),
					),
					'img'    => array(
						'src'   => array(),
						'id'    => array(),
						'class' => array(),
					),
					'a'      => array(
						'href'   => array(),
						'target' => array(),
						'class'  => array(),
						'name'   => array(),
						'id'     => array(),
					),
					'h4'     => array(
						'style' => array(
							'width'   => array(),
							'display' => array(),
						),
						'id'    => array(),
					),
					'h3'     => array(
						'style' => array(
							'width'   => array(),
							'display' => array(),
						),
						'id'    => array(),
					),
					'button' => array(
						'onClick' => array(),
						'type'    => array(),
						'onclick' => array(),
						'class'   => array(),
						'id'      => array(),
					),
					'strong' => array(),
					'input'  => array(
						'type'       => array(),
						'class'      => array(),
						'id'         => array(),
						'name'       => array(),
						'value'      => array(),
						'formaction' => array(),
						'style'      => array(
							'position'     => array(),
							'padding'      => array(),
							'background'   => array(),
							'right'        => array(),
							'color'        => array(),
							'border-color' => array(),
							'cursor'       => array(),
						),
					),
					'form'   => array(
						'id'     => array(),
						'method' => array(),
						'action' => array(),
						'style'  => array(
							'margin-top'  => array(),
							'margin-left' => array(),
							'display'     => array(),
						),
					),
				);
				$consent_url   = home_url( '/wp-content/plugins/google-sitemap-generator/upgrade-plugin.php' );
				$decline_consent_url = ( empty( $_SERVER['HTTPS'] ) ? 'http' : 'https' ) . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

				$qs = 'settings_page_google-sitemap-generator/sitemap' === $current_screen ? '&action=no' : '?action=no';
				/* translators: %s: search term */
				echo wp_kses(
					__(
						'
						<h4>Do you want the best SEO indexation technology for your website? Join the Google XML Sitemaps Beta Program now!</h4>
						<input type="hidden" id="action" name="action" value="my_action" >
						<div class="justify-content">
						<a href="' . $consent_url . '?action=yes" id="user_consent" class="allow_beta_consent" target="blank" name="user_consent" >Yes, I am in</a>
						<a href="' . $decline_consent_url . $qs . '" id="discard_content" class="discard_button" name="discard_consent">X</a>
						</div>
						',
						'sitemap'
					),
					$arr
				);
				?>
		</div>
				<?php
		}
		?>
		<?php
		$default_value = 'default';
		$consent_value = get_option( 'sm_user_consent', $default_value );
		if ( $default_value === $consent_value && 'google-sitemap-generator/sitemap.php' === $current_page ) {
			/* translators: %s: search term */
			echo wp_kses(
				sprintf(
					__(
						'
						<div class="modal-wrapper" id="modal-wrapper">
							<div class="modal-container">
							<h3>Help Us Improve!</h3>
							<p>Would you help us improve Google XML Sitemaps by sharing anonymous usage data?</p>
							<p>Understanding feature usage and use cases better means we can provide you with the best indexation and indexing performance.</p>
							<p><a href="https://auctollo.com/policies/privacy/" target="_blank">We respect your privacy!</a></p>
							<p>&nbsp;</p>
							<form method="POST">
								<input type="submit" name="user_consent_yes" class="allow_consent" value="I want the best!" />
								<input type="submit" name="user_consent_no" class="decline_consent" value="I don\'t know what I want" />
							</form>
							</div>
						</div>
						',
						'sitemap'
					),
					function() {
					}
				),
				$arr
			);
		}
			/* translators: %s: search term */
		?>
		<?php
		if ( 'google-sitemap-generator/sitemap.php' === $current_page ) {
			/* translators: %s: search term */
			echo wp_kses(
				sprintf(
					__(
						'
						<div class="cookie-info-banner-wrapper" id="cookie-info-banner-wrapper">
							<div class="modal-container">
							<h3>Help Us Improve!</h3>
								<button class="close_popup" id="close_popup">
								<img class="close_cookie_information" src="' . $image_url . '" />
								</button>
								<p>Would you help us improve Google XML Sitemaps by sharing anonymous usage data? We intend to understand feature usage and use cases better so that we can provide you with the best indexation and indexing performance.</p>
							</div>
						</div>
						',
						'sitemap'
					),
					function() {
					}
				),
				$arr
			);
		}
			/* translators: %s: search term */
		$default_value           = 'default';
		$auto_update_plugins     = get_option( 'auto_update_plugins', $default_value );
		$hide_auto_update_banner = get_option( 'sm_hide_auto_update_banner' );
		if ( ! is_array( $auto_update_plugins ) ) {
			$auto_update_plugins = array();
		}
		if ( ! in_array( 'google-sitemap-generator/sitemap.php', $auto_update_plugins, true ) && 'google-sitemap-generator/sitemap.php' === $current_page && 'yes' !== $hide_auto_update_banner ) {
			?>
			<style>
				.justify-content{
					display: flex;
					justify-content: space-between;
					align-items: center;
				}
				a.do_not_enable_auto_update{
					border-radius: 50%;
					border: 0px;
					text-align: center;
					justify-content: center;
					align-items: center;
					margin-left: 40px;
					margin-right: 5px;
					cursor: pointer;
					height: 20px;
					background-color: #787c82;
					color: white;
					font-size: small;
					font-weight: bold;
					width: 20px;
					padding-bottom: 0px;
					text-decoration: none;
				}
				a.enable_auto_update {
					background: #2271b1;
					color: white;
					border-color: #2271b1;
					cursor: pointer;
					padding: 8px;
					text-decoration: none;
				}
				.enable_auto_update:hover{
					color: white;
					outline: 1px solid #2271b1;
				}
		</style>
		<div class="updated notice" style="display: flex;justify-content:space-between;">
			<?php
			/* translators: %s: search term */
			echo wp_kses(
				sprintf(
					__(
						'
						<h4>Auto-updates aren not enabled for Sitemap Generator. Would you like to enable auto-updates to always have the best indexation features?
						</h4>
						<form method="post" id="enable-updates-form">
						<input type="hidden" id="enable_updates" name="enable_updates" value="false" />
						</form>
						<div class="justify-content">
						<a href="" id="enable_auto_update" class="enable_auto_update" name="enable_auto_update" >Enable Auto-Updates!</a>
						<a href="" id="do_not_enable_auto_update" class="do_not_enable_auto_update" name="do_not_enable_auto_update">X</a>

						</div>
						',
						'sitemap'
					),
					function() {
					}
				),
				$arr
			);
			/* translators: %s: search term */
			?>
		</div>
			<?php
		}
	}
	/**
	 * Returns a nice icon for the Ozh Admin Menu if the {@param $hook} equals to the sitemap plugin
	 *
	 * @param string $hook The hook to compare .
	 * @return string The path to the icon
	 */
	public static function register_admin_icon( $hook ) {
		if ( self::get_base_name() === $hook && function_exists( 'plugins_url' ) ) {
			return plugins_url( 'img/icon-arne.gif', self::get_base_name() );
		}
		return $hook;
	}

	/**
	 * Registers additional links for the sitemap plugin on the WP plugin configuration page
	 *
	 * Registers the links if the $file param equals to the sitemap plugin
	 *
	 * @param string $links Array An array with the existing links .
	 * @param string $file string The file to compare to .
	 * @return string[]
	 */
	public static function register_plugin_links( $links, $file ) {
		$base = self::get_base_name();
		if ( $file === $base ) {
			$links[] = '<a href="options-general.php?page=' . self::get_base_name() . '">' . __( 'Settings', 'sitemap' ) . '</a>';
			$links[] = '<a href="http://www.arnebrachhold.de/redir/sitemap-plist-faq/">' . __( 'FAQ', 'sitemap' ) . '</a>';
			$links[] = '<a href="http://www.arnebrachhold.de/redir/sitemap-plist-support/">' . __( 'Support', 'sitemap' ) . '</a>';
		}
		return $links;
	}

	/**
	 * SchedulePingOnStatus Change
	 *
	 * @param string $new_status string The new post status .
	 * @param string $old_status string The old post status .
	 * @param object $post WP_Post The post object .
	 */
	public static function schedule_ping_on_status_change( $new_status, $old_status, $post ) {
		if ( 'publish' === $new_status ) {
			set_transient( 'sm_ping_post_id', $post->ID, 120 );
			wp_schedule_single_event( time() + 5, 'sm_ping' );
		}
	}

	/**
	 * Invokes the HtmlShowOptionsPage method of the generator
	 *
	 * @uses GoogleSitemapGeneratorLoader::load_plugin()
	 * @uses GoogleSitemapGenerator::HtmlShowOptionsPage()
	 */
	public static function call_html_show_options_page() {
		if ( self::load_plugin() ) {
			GoogleSitemapGenerator::get_instance()->html_show_options_page();
		}
	}

	/**
	 * Invokes the ShowPingResult method of the generator
	 *
	 * @uses GoogleSitemapGeneratorLoader::load_plugin()
	 * @uses GoogleSitemapGenerator::ShowPingResult()
	 */
	public static function call_show_ping_result() {
		if ( self::load_plugin() ) {
			GoogleSitemapGenerator::get_instance()->show_ping_result();
		}
	}

	/**
	 * Invokes the SendPing method of the generator
	 *
	 * @uses GoogleSitemapGeneratorLoader::load_plugin()
	 * @uses GoogleSitemapGenerator::SendPing()
	 */
	public static function call_send_ping() {
		if ( self::load_plugin() ) {
			GoogleSitemapGenerator::get_instance()->send_ping();
		}
	}

	/**
	 * Invokes the SendPingDaily method of the generator
	 *
	 * @uses GoogleSitemapGeneratorLoader::load_plugin()
	 * @uses GoogleSitemapGenerator::SendPingDaily()
	 */
	public static function call_send_ping_daily() {
		if ( self::load_plugin() ) {
			GoogleSitemapGenerator::get_instance()->send_ping_daily();
		}
	}

	/**
	 * Invokes the ShowSitemap method of the generator
	 *
	 * @param string $options .
	 * @uses GoogleSitemapGeneratorLoader::load_plugin()
	 * @uses GoogleSitemapGenerator::ShowSitemap()
	 */
	public static function call_show_sitemap( $options ) {
		if ( self::load_plugin() ) {
			GoogleSitemapGenerator::get_instance()->show_sitemap( $options );
		}
	}

	/**
	 * Invokes the DoRobots method of the generator
	 *
	 * @uses GoogleSitemapGeneratorLoader::load_plugin()
	 * @uses GoogleSitemapGenerator::DoRobots()
	 */
	public static function call_do_robots() {
		if ( self::load_plugin() ) {
			GoogleSitemapGenerator::get_instance()->do_robots();
		}
	}

	/**
	 * Displays the help links in the upper Help Section of WordPress
	 */
	public static function call_html_show_help_list() {

		$screen = get_current_screen();
		$id     = get_plugin_page_hookname( self::get_base_name(), 'options-general.php' );

	}


	/**
	 * Loads the actual generator class and tries to raise the memory and time limits if not already done by WP
	 *
	 * @uses GoogleSitemapGenerator::enable()
	 * @return boolean true if run successfully
	 */
	public static function load_plugin() {

		$disable_functions = ini_get( 'disable_functions' );

		if ( ! class_exists( 'GoogleSitemapGenerator' ) ) {

			$mem = abs( intval( ini_get( 'memory_limit' ) ) );
			if ( $mem && $mem < 128 ) {
				wp_raise_memory_limit( '128M' );
			}

			$time = abs( intval( ini_get( 'max_execution_time' ) ) );
			if ( 0 !== $time && 120 > $time ) {
				if ( strpos( $disable_functions, 'set_time_limit' ) === false ) {
					set_time_limit( 120 );
				}
			}

			$path = trailingslashit( dirname( __FILE__ ) );

			if ( ! file_exists( $path . 'sitemap-core.php' ) ) {
				return false;
			}
			require_once $path . 'sitemap-core.php';
		}

		GoogleSitemapGenerator::enable();
		return true;
	}

	/**
	 * Returns the plugin basename of the plugin (using __FILE__)
	 *
	 * @return string The plugin basename, 'sitemap' for example
	 */
	public static function get_base_name() {
		return plugin_basename( sm_get_init_file() );
	}

	/**
	 * Returns the name of this loader script, using sm_GetInitFile
	 *
	 * @return string The sm_GetInitFile value
	 */
	public static function get_plugin_file() {
		return sm_get_init_file();
	}

	/**
	 * Returns the plugin version
	 *
	 * Uses the WP API to get the meta data from the top of this file (comment)
	 *
	 * @return string The version like 3.1.1
	 */
	public static function get_version() {
		if ( ! isset( $GLOBALS['sm_version'] ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				if ( file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				} else {
					return '0.ERROR';
				}
			}
			$data                  = get_plugin_data( self::get_plugin_file(), false, false );
			$GLOBALS['sm_version'] = $data['Version'];
		}
		return $GLOBALS['sm_version'];
	}

	/**
	 * Get SVN function .
	 */
	public static function get_svn_version() {
		return self::$svn_version;
	}
}

// Enable the plugin for the init hook, but only if WP is loaded. Calling this php file directly will do nothing.
if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
	add_action( 'init', array( 'GoogleSitemapGeneratorLoader', 'Enable' ), 15, 0 );
	add_action( 'admin_notices', array( 'GoogleSitemapGeneratorLoader', 'beta_notice' ), 15, 0 );
	register_activation_hook( sm_get_init_file(), array( 'GoogleSitemapGeneratorLoader', 'activate_plugin' ) );
	register_deactivation_hook( sm_get_init_file(), array( 'GoogleSitemapGeneratorLoader', 'deactivate_plugin' ) );

	// Set up hooks for adding permalinks, query vars.
	// Don't wait until init with this, since other plugins might flush the rewrite rules in init already...
	GoogleSitemapGeneratorLoader::setup_query_vars();
	GoogleSitemapGeneratorLoader::setup_rewrite_hooks();
}
