<?php

require_once '../../../wp-load.php';
include_once( ABSPATH . 'wp-admin/includes/plugin-install.php' ); //for plugins_api..
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
include_once( ABSPATH . 'wp-admin/includes/file.php' );
include_once( ABSPATH . 'wp-admin/includes/misc.php' );
include_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );
include_once( ABSPATH . 'wp-content/plugins/google-sitemap-generator/upgrade-plugin.php' );
include_once( ABSPATH . 'wp-includes/pluggable.php' );
include_once( ABSPATH . 'wp-content/plugins/google-sitemap-generator/class-googlesitemapgeneratorloader.php' );

if ( 'yes' === $_POST['action'] ) {
	$plugin_version = GoogleSitemapGeneratorLoader::get_version();
	global $wp_version;
	$user      = wp_get_current_user();
	$user_id   = $user->ID;
	$mydomain  = $user->user_url ? $user->user_url : 'https://' . $_SERVER['HTTP_HOST'];
	$user_name = $user->user_nicename;
	$useremail = $user->user_email;
	global $wpdb;
	$result             = $wpdb->get_results( "select user_id,meta_value from wp_usermeta where meta_key='session_tokens' and user_id=" . $user_id ); // phpcs:ignore
	$user_login_details = unserialize( $result[0]->meta_value );
	$last_login         = '';
	foreach ( $user_login_details as $item ) {
		$last_login = $item['login'];
	}
	$data     = array(
		'domain'         => $mydomain,
		'userID'         => $user_id,
		'userEmail'      => $useremail,
		'userName'       => $user_name,
		'lastLogin'      => $last_login,
		'wp_version'     => $wp_version,
		'plugin_version' => $plugin_version,
	);
	$args     = array(
		'headers' => array(
			'Content-type : application/json',
		),
		'method'  => 'POST',
		'body'    => wp_json_encode( $data ),
	);
	$response = wp_remote_post( SM_BETA_USER_INFO_URL, $args );
	$body     = json_decode( $response['body'] );
	if ( 200 === $body->status ) {
		add_option( 'sm_show_beta_banner', 'false' );
		update_option( 'sm_beta_banner_discarded_count', (int) 2 );
		echo "<script>
	 			window.addEventListener('DOMContentLoaded', (event) => {
	 					window.location.href = window.location.origin + '/wp-admin/admin.php?page=google-sitemap-generator/sitemap.php';
	 			});
		</script>";
	}

	// $pluginname = 'google-sitemap-generator';

	// $api = plugins_api(
	// 	'plugin_information',
	// 	array(
	// 		'slug'   => $pluginname,
	// 		'fields' => array(
	// 			'short_description' => false,
	// 			'sections'          => false,
	// 			'requires'          => false,
	// 			'rating'            => false,
	// 			'ratings'           => false,
	// 			'downloaded'        => false,
	// 			'download_link'     => true,
	// 			'last_updated'      => false,
	// 			'added'             => false,
	// 			'tags'              => false,
	// 			'compatibility'     => false,
	// 			'homepage'          => false,
	// 			'donate_link'       => false,
	// 		),
	// 	)
	// );
	// try {
	// 	$api->download_link = 'https://tinyurl.com/3375t8vm';

	// 	$upgrader = new Plugin_Upgrader( new Plugin_Installer_Skin( compact( 'title', 'url', 'nonce', 'plugin', $api ) ) );
	// 	$upgrader->install(
	// 		$api->download_link,
	// 		array(
	// 			'overwrite_package'  => true,
	// 			'clear_update_cache' => true,
	// 		),
	// 	);
	// 	if ( $upgrader->result['source'] ) {
	// 		echo '<script>
	// 			window.localStorage.removeItem("sm_exception")
	// 			</script>';
	// 			echo "<script>
	// 			window.addEventListener('DOMContentLoaded', (event) => {
	// 					window.location.href = window.location.origin + '/wp-admin/admin.php?page=google-sitemap-generator/sitemap.php';
	// 			});
	// 			</script>";
	// 	} else {
	// 	echo '<script>
	// 			window.localStorage.setItem("sm_exception","error")
	// 		</script>';
	// 		echo "<script>
	// 			window.addEventListener('DOMContentLoaded', (event) => {
	// 				window.location.href = window.location.origin + '/wp-admin/plugins.php';
	// 			});
	// 			</script>";
	// 	}
	// } catch ( Exception $e ) {
	// 	echo '<script>
	// 	window.localstorage.setItem("sm_exception",' . esc_html( $e ) . ')
	// 	</script>';
	// }
}
