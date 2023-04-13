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

if ( isset( $_GET['action'] ) ) {
	if ( 'yes' === $_GET['action'] ) {
		update_option( 'sm_user_consent', 'yes' );
		$plugin_version = GoogleSitemapGeneratorLoader::get_version();
		global $wp_version;
		$user      = wp_get_current_user();
		$user_id   = $user->ID;
		$mydomain  = $user->user_url ? $user->user_url : home_url();
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
			'phpVersion'     => PHP_VERSION,
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
			add_option( 'sm_beta_opt_in', true );
			update_option( 'sm_beta_banner_discarded_count', (int) 2 );
			echo "<script>
					window.addEventListener('DOMContentLoaded', (event) => {
							var url = '" . SM_LEARN_MORE_API_URL . "/?utm_source=wordpress&utm_medium=notification&utm_campaign=beta&utm_id=v4'
							var link = document.createElement('a');
							link.href = url;
							document.body.appendChild(link);
							link.click();
					});
			</script>";
		}
	}
}
