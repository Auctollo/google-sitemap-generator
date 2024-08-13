<?php
/* IndexNow class */

class GoogleSitemapGeneratorIndexNow {

    private static $siteUrl;
    private static $version;
    private static $prefix = "gsg_indexnow-";
	private static $table_name = 'sm_indexnow_tasks';

    public static function start( $indexUrl ) {
		self::$siteUrl = get_home_url();
		self::$version = self::get_version();
		$apiKey = self::get_api_key();
		
		return self::send_to_index( self::$siteUrl, $indexUrl, $apiKey, false );
    }

    private static function send_to_index( $site_url, $url, $api_key, $is_manual_submission ) {
        
        $data = json_encode(
			array(
				'host'         => self::remove_scheme( $site_url ),
				'key'          => $api_key,
				'keyLocation'  => trailingslashit( $site_url ) . $api_key . '.txt',
				'urlList'     => array( $url ),
			)
		);

        $response = wp_remote_post(
            'https://api.indexnow.org/indexnow/',
            array(
                'body'    => $data,
                'headers' => array( 
                    'Content-Type'  => 'application/json',
                    'X-Source-Info' => 'https://wordpress.com/' . self::$version . '/' . $is_manual_submission
                ),
            )
        );

		if (is_wp_error( $response )) {
			if ( true === WP_DEBUG && true === WP_DEBUG_LOG) {
			    error_log(__METHOD__ . " error:WP_Error: ".$response->get_error_message()) ;
			}
			return "error:WP_Error";
		}
		if ( isset( $response['errors'] ) ) {
			return 'error:RequestFailed';
		}
		try {
			if (in_array($response['response']['code'], [200, 202])) {
				return 'success';
			} else {
				if ( 400 === $response['response']['code'] ) {
					return 'error:InvalidRequest';
				} else 
				 if ( 403 === $response['response']['code'] ) {
					 return 'error:InvalidApiKey';
				 } else 
				 if ( 422 === $response['response']['code'] ) {
					 return 'error:InvalidUrl';
				 }else 
				if ( 429 === $response['response']['code'] ) {
					return 'error:UnknownError';
				}else {
					return 'error: ' . $response['response']['message'];
					if ( true === WP_DEBUG && true === WP_DEBUG_LOG) {
						error_log(__METHOD__ . " body : ". json_decode($response['body'])->message) ;
					}
				}
			}
		} catch ( \Throwable $th ) {
			return 'error:RequestFailed';
		}

    }

	public static function create_table() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE {$wpdb->prefix}" . self::$table_name . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			link TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE (link(255))
		) $charset_collate;";
		
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	public static function create_task( $post ) {
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . self::$table_name, array(
            'link' 			=> get_permalink( $post ), 
            'status' 		=> 'pending'
		), array( '%s', '%s' ));
	}

	public static function add_cron_schedule() {
		if ( ! wp_next_scheduled( 'indexnow_cron_event' ) ) {
			wp_schedule_event( time(), 'daily', 'indexnow_cron_event' );
		}
	}

	public static function remove_cron_schedule() {
		if ( wp_next_scheduled( 'indexnow_cron_event' ) ) {
			wp_clear_scheduled_hook( 'indexnow_cron_event' );
		}
	}

	public static function cron_watcher() {
		add_action( 'indexnow_cron_event', ['GoogleSitemapGeneratorIndexNow', 'cron_event_functions'] );
	}

	public static function cron_event_functions() {
		self::process_pending_tasks();
		self::clean_up_old_tasks();
	}

	public static function process_pending_tasks() {
		global $wpdb;
		$tasks = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}" . self::$table_name . " WHERE status = 'pending'" );

		foreach ( $tasks as $task ) {
			$response = self::start( get_permalink( $task->link ) );

			if ( $response === 'success' ) {
				$wpdb->update(
					"{$wpdb->prefix}" . self::$table_name,
					array( 'status' => 'completed' ),
					array( 'id' => $task->id )
				);
			}
		}
	}

	public static function clean_up_old_tasks() {
		global $wpdb;
		// Delete tasks that were processed more than 30 days ago
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}" . self::$table_name . " WHERE status = 'completed' AND updated_at < NOW() - INTERVAL 30 DAY"
			)
		);
	}

	public static function set_api_key() {
		$api_key = wp_generate_uuid4();
		$api_key = preg_replace('[-]', '', $api_key);

		if( is_multisite() ){
			update_site_option('gsg_indexnow-is_valid_api_key', '2');
			update_site_option('gsg_indexnow-admin_api_key', base64_encode( $api_key ));
		} else {
			update_option( 'gsg_indexnow-is_valid_api_key', '2' );
			update_option( 'gsg_indexnow-admin_api_key', base64_encode( $api_key ) );
		}
	}

    public static function get_api_key() {
		$meta_key = self::$prefix . "admin_api_key";
        $apiKey = is_multisite() ? get_site_option($meta_key) : get_option($meta_key);
        if ($apiKey) return base64_decode($apiKey);

		return false;
    }

	public static function remove_api_key() {
		if( is_multisite() ){
			delete_site_option( 'gsg_indexnow-is_valid_api_key' );
			delete_site_option( 'gsg_indexnow-admin_api_key' );
		} else {
			delete_option( 'gsg_indexnow-is_valid_api_key' );
			delete_option( 'gsg_indexnow-admin_api_key' );
		}
	}

	private static function remove_scheme( $url ) {
		if ( 'http://' === substr( $url, 0, 7 ) ) {
			return substr( $url, 7 );
		}
		if ( 'https://' === substr( $url, 0, 8 ) ) {
			return substr( $url, 8 );
		}
		return $url;
	}

    private static function get_version() {
        if ( isset( $GLOBALS['sm_version']) ) {
            self::$version = $GLOBALS['sm_version'];
        } else {
            self::$version = '1.0.1';
        }
    }

	public static function activation() {
		self::set_api_key();
		self::create_table();
		self::add_cron_schedule();
	}

	public static function deactivation() {
		self::remove_api_key();
		self::remove_cron_schedule();
	}
}

GoogleSitemapGeneratorIndexNow::cron_watcher();