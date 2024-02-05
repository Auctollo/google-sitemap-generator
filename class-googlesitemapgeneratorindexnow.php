<?php
/* IndexNow class */

class GoogleSitemapGeneratorIndexNow {

    private $siteUrl;
    private $version;
    private $prefix = "gsg_indexnow-";

    public function start($indexUrl){
		$this->siteUrl = get_home_url();
		$this->version = $this->getVersion();
		$apiKey = $this->getApiKey();
		
		return $this->sendToIndex($this->siteUrl, $indexUrl, $apiKey, false);
    }

    public function getApiKey() {
		$meta_key = $this->prefix . "admin_api_key";
        $apiKey = is_multisite() ? get_site_option($meta_key) : get_option($meta_key);
        if ($apiKey) return base64_decode($apiKey);

		return false;
    }

    private function sendToIndex($site_url, $url, $api_key, $is_manual_submission){
        
        $data = json_encode(
			array(
				'host'         => $this->remove_scheme( $site_url ),
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
                    'X-Source-Info' => 'https://wordpress.com/' . $this->version . '/' . $is_manual_submission
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

    private function remove_scheme( $url ) {
		if ( 'http://' === substr( $url, 0, 7 ) ) {
			return substr( $url, 7 );
		}
		if ( 'https://' === substr( $url, 0, 8 ) ) {
			return substr( $url, 8 );
		}
		return $url;
	}

    private function getVersion(){
        if ( isset( $GLOBALS['sm_version']) ) {
            $this->version = $GLOBALS['sm_version'];
        } else {
            $this->version = '1.0.1';
        }
    }

}