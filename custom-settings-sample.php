<?php

/**
 * change settings for single pages or posts
 *
 * @package Sitemap
 * @author  maruhe
 */

/*
rename this file to custom-settings.php to activate custom settings
*/

function GoogleSitemapGenerator_CustomPostSettings($postID, &$permalink, &$last_mod, &$change_freq, &$priority) {
    global $wpdb;
    
    if ($postID == 35) {
        #sample 1 for page 35 => set priority to 100
        $priority = 1;
    } else if ($postID == 88) {
        #sample 2 for post 88 => get update-time from custom table
        $priority = 1;
        $change_freq = 'daily';
        try {
            $last_mod = $wpdb->get_var("SELECT UNIX_TIMESTAMP(`DateTime`) FROM " . $wpdb->base_prefix . "my_table ORDER BY `DateTime` DESC LIMIT 1;");
        } catch (Throwable $e) {
            $last_mod = time();
        }
    }
}