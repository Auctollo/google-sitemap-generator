<?php
/**
 * Default sitemap builder
 *
 * @package Sitemap
 * @author  Arne Brachhold
 * @since   4.0
 */
/**
 * Class
 */
class GoogleSitemapGeneratorStandardBuilder {

	private $linkPerPage = 1000;
	private $maxLinksPerPage = 50000;
	/**
	 * Holds image parser instance.
	 *
	 * @var GoogleSitemapGeneratorImageParser
	 */
	protected static $image_parser;

	/**
	 * Determines whether images should be included in the XML sitemap.
	 *
	 * @var bool
	 */
	private $include_images;

	public $providers;

	/**
	 * Creates a new GoogleSitemapGeneratorStandardBuilder instance
	 */
	public function __construct() {

		add_action( 'sm_build_index', array( $this, 'index' ), 10, 1 );
		add_action( 'sm_build_content', array( $this, 'content' ), 10, 3 );

		add_filter( 'sm_sitemap_for_post', array( $this, 'get_sitemap_url_for_post' ), 10, 3 );

		/**
		 * Filter - Allows excluding images from the XML sitemap.
		 *
		 * @param bool $include True to include, false to exclude.
		 */
		$this->include_images = apply_filters( 'sm_xml_sitemap_include_images', true );
	}

	/**
	 * Get the Image Parser.
	 *
	 * @return GoogleSitemapGeneratorImageParser
	 */
	protected function get_image_parser() {
		if ( ! isset( self::$image_parser ) ) {
			self::$image_parser = new GoogleSitemapGeneratorImageParser();
		}

		return self::$image_parser;
	}

	/**
	 * Generates the content of the requested sitemap
	 *
	 * @param GoogleSitemapGenerator $gsg instance of sitemap generator.
	 * @param String                 $type the type of the sitemap.
	 * @param String                 $params Parameters for the sitemap.
	 */
	public function content( $gsg, $type, $params ) {

		$params = strval($params);
		if (strpos($params, '/') !== false){
            $newType = explode('/', $params);
            $params = end($newType);
        }

		switch ( $type ) {
			case 'pt':
				$this->build_posts( $gsg, $params );
				break;
			case 'archives':
				$this->build_archives( $gsg );
				break;
			case 'authors':
				$this->build_authors( $gsg );
				break;
			case 'tax':
				$this->build_taxonomies( $gsg, $params );
				break;
			case 'producttags':
				$this->build_product_tags( $gsg, $params );
				break;
			case 'productcat':
				$this->build_product_categories( $gsg, $params );
				break;
			case 'externals':
				$this->build_externals( $gsg );
				break;
			case 'misc':
				$this->build_misc( $gsg );
				break;
			default:
				break;
		}
	}

	/**
	 * Generates the content for the post sitemap
	 *
	 * @param GoogleSitemapGenerator $gsg instance of sitemap generator.
	 * @param string                 $params string.
	 */
	public function build_posts( $gsg, $params ) {

		$pts = strrpos( $params, '-' );

		if ( ! $pts ) {
			return;
		}

		$pts = strrpos( $params, '-', $pts - strlen( $params ) - 1 );

		$param_length   = count( explode( '-', $params ) );
		$post_type = '';
		$post_type      = substr( $params, 0, $pts );
		$type           = explode( '-', $post_type );
		if ( $param_length > 4 ) {
			$new = array_slice( $type,0,count($type) -1 );
			$post_type = implode( "-", $new );
		} else	{
			$post_type  = $type[0];
		}
		$limit          = $type[count($type)-1];
		$limits         = substr( $limit, 1 );
		$links_per_page = $gsg->get_entries_per_page();
		if ( gettype( $links_per_page ) !== 'integer' ) {
			$links_per_page = (int) 1000;
		}
		$limit          = ( (int) $limits ) * $links_per_page;
		if ( ! $post_type || ! in_array( $post_type, $gsg->get_active_post_types(), true ) ) {
			return;
		}

			$params = substr( $params, $pts + 1 );

			/**
			 * Global variable for database.
			 *
			 * @var $wpdb wpdb
			 */
			global $wpdb;

		if ( preg_match( '/^([0-9]{4})\-([0-9]{2})$/', $params, $matches ) ) {
			$year  = $matches[1];
			$month = $matches[2];

			// Excluded posts by ID.
			$excluded_post_ids = $gsg->get_excluded_post_ids( $gsg );
			$not_allowed_slugs = $gsg->robots_disallowed();
			$excluded_post_ids = array_unique( array_merge( $excluded_post_ids, $not_allowed_slugs ), SORT_REGULAR );
			$gsg->set_option( 'b_exclude', $excluded_post_ids );
			$gsg->save_options();
			$ex_post_s_q_l = '';
			if ( count( $excluded_post_ids ) > 0 ) {
				$ex_post_s_q_l = 'AND p.ID NOT IN (' . implode( ',', $excluded_post_ids ) . ')';
			}

			// Excluded categories by taxonomy ID.
			$excluded_category_i_d_s = $gsg->get_excluded_category_i_ds( $gsg );
			$ex_cat_s_q_l            = '';
			if ( count( $excluded_category_i_d_s ) > 0 ) {
				$ex_cat_s_q_l = "AND ( p.ID NOT IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ( SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id IN ( " . implode( ',', $excluded_category_i_d_s ) . '))))';
			}
			// Statement to query the actual posts for this post type.
			$sort_order = $gsg->get_sitemap_sort_order();
			$qs = "
				SELECT
					p.ID,
					p.post_author,
					p.post_status,
					p.post_name,
					p.post_content,
					p.post_parent,
					p.post_type,
					p.post_date,
					p.post_date_gmt,
					p.post_modified,
					p.post_modified_gmt,
					p.comment_count
				FROM
					{$wpdb->posts} p
				WHERE
					p.post_password = ''
					AND p.post_type = '%s'
					AND p.post_status = 'publish'
					{$ex_post_s_q_l}
					{$ex_cat_s_q_l}
				ORDER BY
					p.post_date_gmt {$sort_order}
				LIMIT
					%d, %d
			";
			// Query for counting all relevant posts for this post type.
			$qsc = "
				SELECT
					COUNT(*)
				FROM
					{$wpdb->posts} p
				WHERE
					p.post_password = ''
					AND p.post_type = '%s'
					AND p.post_status = 'publish'
					{$ex_post_s_q_l}
					{$ex_cat_s_q_l}
			";

			// Calculate the offset based on the limit and links_per_page
			$offset = max( 0, ( $limit - $links_per_page ) );

			// phpcs:disable
			$q = $wpdb->prepare( $qs, $post_type, $offset, $links_per_page );

			// phpcs:enable
			$posts      = $wpdb->get_results( $q ); // phpcs:ignore
			$post_count = count( $posts );
			if ( ( $post_count ) > 0 ) {
				/**
				 * Description for priority provider
				 *
				 * @var $priority_provider GoogleSitemapGeneratorPrioProviderBase
				 */
				$priority_provider = null;

				if ( $gsg->get_option( 'b_prio_provider' ) !== '' ) {

					// Number of comments for all posts.
					$cache_key     = __CLASS__ . '::commentCount';
					$comment_count = wp_cache_get( $cache_key, 'sitemap' );
					if ( false === $comment_count ) {
						$comment_count = $wpdb->get_var( "SELECT COUNT(*) as `comment_count` FROM {$wpdb->comments} WHERE `comment_approved`='1'" );  // db call ok.
						wp_cache_set( $cache_key, $comment_count, 'sitemap', 20 );
					}

					// Number of all posts matching our criteria.
					$cache_key        = __CLASS__ . "::totalPostCount::$post_type";
					$total_post_count = wp_cache_get( $cache_key, 'sitemap' );
					if ( false === $total_post_count ) {
						// phpcs:disable
						$total_post_count = $wpdb->get_var(
							$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_password = '' AND p.post_type = '%s' AND p.post_status = 'publish' " . $ex_post_s_q_l . " " . $ex_cat_s_q_l . " ",  $post_type ) // phpcs:ignore
						); // db call ok.
						// phpcs:enable
						wp_cache_add( $cache_key, $total_post_count, 'sitemap', 20 );
					}

					// Initialize a new priority provider.
					$provider_class    = $gsg->get_option( 'b_prio_provider' );
					$priority_provider = new $provider_class( $comment_count, $total_post_count );
				}

				// Default priorities.
				$default_priority_for_posts = $gsg->get_option( 'pr_posts' );
				$default_priority_for_pages = $gsg->get_option( 'pr_pages' );

				// Minimum priority.
				$minimum_priority = $gsg->get_option( 'pr_posts_min' );

				// Change frequencies.
				$change_frequency_for_posts = $gsg->get_option( 'cf_posts' );
				$change_frequency_for_pages = $gsg->get_option( 'cf_pages' );

				// Page as home handling.
				$home_pid = 0;
				$home     = get_home_url();
				if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
					$page_on_front = get_option( 'page_on_front' );
					$p             = get_post( $page_on_front );
					if ( $p ) {
						$home_pid = $p->ID;
					}
				}

				$siteLanguages = [];
				$defaultLanguageCode = '';

				if (function_exists('icl_get_languages')) {
					if (function_exists('icl_get_default_language')) $defaultLanguageCode = icl_get_default_language();
					$languages = icl_get_languages('skip_missing=0');
					if($languages){
						foreach ($languages as $language) {
							if($defaultLanguageCode !== $language['language_code']) $siteLanguages[] = $language['language_code'];
						}
					}
				} else if (function_exists('pll_the_languages')) {
					if (function_exists('pll_default_language')) $defaultLanguageCode = pll_default_language();
					$languages = pll_the_languages(array('raw' => 1));
					if ($languages) {
						foreach ($languages as $language) {
							if($defaultLanguageCode !== $language['slug']) $siteLanguages[] = $language['slug'];
						}
					}
				}

				foreach ( $posts as $post ) {

					$permalink = get_permalink( $post );

					$permalink = apply_filters( 'sm_xml_sitemap_post_url', $permalink, $post );

					if(count($siteLanguages) > 0){

						$structurekArr = explode('/', get_option('permalink_structure'));
						$postLinkArr = explode('/', $permalink);

						$index = null;
						if(is_array($structurekArr) && is_array($postLinkArr)){
							foreach ($siteLanguages as $lang){
								if (in_array($lang, $postLinkArr)) {
									$index = array_search($lang, $postLinkArr);
								}
							}
						}

                        if ($index !== null) {
							if ($postLinkArr[$index] !== $defaultLanguageCode) {
								$custom_post_type_name = get_post_type($post);
								if (!in_array($custom_post_type_name, $postLinkArr)) {
									foreach($structurekArr as $key => $value) {
										if ($value == '%postname%') {
											$current_key = array_search($post->post_name, $postLinkArr);
											unset($postLinkArr[$current_key]);
											$postLinkArr[$index + $key] = $post->post_name;
											ksort($postLinkArr);
										}
									}
								}
								$permalink = implode('/', $postLinkArr);
								$permalink = user_trailingslashit($permalink);
							}
						}
					}

					// Exclude the home page and placeholder items by some plugins. Also include only internal links.
					if (
						( ! empty( $permalink ) )
						&& $permalink !== $home
						&& $post->ID !== $home_pid
						&& strpos( $permalink, $home ) !== false
					) {

						// Default Priority if auto calc is disabled.
						$priority = ( 'page' === $post_type ? $default_priority_for_pages : $default_priority_for_posts );

						// If priority calc. is enabled, calculate (but only for posts, not pages)!
						if ( null !== $priority_provider && 'post' === $post_type ) {
							$priority = $priority_provider->get_post_priority( $post->ID, $post->comment_count, $post );
						}

						// Ensure the minimum priority.
						if ( 'post' === $post_type && $minimum_priority > 0 && $priority < $minimum_priority ) {
							$priority = $minimum_priority;
						}

						$images = ! is_null( $this->get_image_parser() ) ? $this->get_image_parser()->get_images( $post ) : [];

						// Add the URL to the sitemap.
						$gsg->add_url(
							$permalink,
							$gsg->get_timestamp_from_my_sql( $post->post_modified_gmt && '0000-00-00 00:00:00' !== $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_date_gmt ),
							( 'page' === $post_type ? $change_frequency_for_pages : $change_frequency_for_posts ),
							$priority,
							$images,
							$post->ID
						);
					}

					// Why not use clean_post_cache? Because some plugin will go crazy then (lots of database queries).
					// The post cache was not populated in a clean way, so we also won't delete it using the API.
					// wp_cache_delete( $post->ID, 'posts' );.
					unset( $post );
				}
				unset( $posts_custom );
			}
		}
	}


	/**
	 * Generates the content for the archives sitemap
	 *
	 * @param GoogleSitemapGenerator $gsg object of google sitemap.
	 */
	public function build_archives( $gsg ) {
		/**
		 * Super global variable for database.
		 *
		 * @var $wpdb wpdb
		 */
		global $wpdb;

		$now = current_time( 'mysql', true );

		$archives = $wpdb->get_results(
			// phpcs:disable
			$wpdb->prepare(
				"SELECT DISTINCT
					YEAR(post_date_gmt) AS `year`,
					MONTH(post_date_gmt) AS `month`,
					MAX(post_date_gmt) AS last_mod,
					count(ID) AS posts
				FROM
					$wpdb->posts
				WHERE
					post_date_gmt < '%s'
					AND post_status = 'publish'
					AND post_type = 'post'
				GROUP BY
					YEAR(post_date_gmt),
					MONTH(post_date_gmt)
				ORDER BY
				post_date_gmt DESC",
				$now
			)
			// phpcs:enable
		); // db call ok; no-cache ok.

		if ( $archives ) {
			foreach ( $archives as $archive ) {

				$url = get_month_link( $archive->year, $archive->month );

				// Archive is the current one.
				if ( gmdate( 'n' ) === $archive->month && gmdate( 'Y' ) === $archive->year ) {
					$change_freq = $gsg->get_option( 'cf_arch_curr' );
				} else { // Archive is older.
					$change_freq = $gsg->get_option( 'cf_arch_old' );
				}

				$gsg->add_url( $url, $gsg->get_timestamp_from_my_sql( $archive->last_mod ), $change_freq, $gsg->get_option( 'pr_arch' ) );
			}
		}

		$post_type_customs = get_post_types( array( 'public' => 1 ) );
		$post_type_customs = array_diff( $post_type_customs, array( 'page', 'attachment', 'product', 'post' ) );
		foreach ( $post_type_customs as $post_type_custom ) {
			$latest = new WP_Query(
				array(
					'post_type'      => $post_type_custom,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);

			if ( $latest->have_posts() ) {
				$modified_date = $latest->posts[0]->post_modified;
				if ( gmdate( 'n', strtotime( $modified_date ) ) === gmdate( 'n' ) && gmdate( 'Y', strtotime( $modified_date ) ) === gmdate( 'Y' ) ) {
					$change_freq = $gsg->get_option( 'cf_arch_curr' );
				} else { // Archive is older.
					$change_freq = $gsg->get_option( 'cf_arch_old' );
				}
				$gsg->add_url( get_post_type_archive_link( $post_type_custom ), $gsg->get_timestamp_from_my_sql( $modified_date ), $change_freq, $gsg->get_option( 'pr_arch' ), 0, array(), array(), '' );
			}
		}
	}

	/**
	 * Generates the misc sitemap
	 *
	 * @param GoogleSitemapGenerator $gsg instence of sitemap generator class.
	 */
	public function build_misc( $gsg ) {
		$lm = get_lastpostmodified( 'gmt' );

		if ( $gsg->get_option( 'in_home' ) ) {
			$home = get_bloginfo( 'url' );

			// Add the home page (WITH a slash!).
			if ( $gsg->get_option( 'in_home' ) ) {
				if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
					$page_on_front = get_option( 'page_on_front' );
					$p             = get_post( $page_on_front );
					if ( $p ) {
						$gsg->add_url(
							trailingslashit( $home ),
							$gsg->get_timestamp_from_my_sql( ( $p->post_modified_gmt && '0000-00-00 00:00:00' !== $p->post_modified_gmt ? $p->post_modified_gmt : $p->post_date_gmt ) ),
							$gsg->get_option( 'cf_home' ),
							$gsg->get_option( 'pr_home' )
						);
					}
				} else {
					$gsg->add_url(
						trailingslashit( $home ),
						( $lm ? $gsg->get_timestamp_from_my_sql( $lm ) : time() ),
						$gsg->get_option( 'cf_home' ),
						$gsg->get_option( 'pr_home' )
					);
				}
			}
		}

		if ( $gsg->is_xsl_enabled() && true === $gsg->get_option( 'b_html' ) ) {
			if(is_multisite()) {
				if(isset(get_blog_option( get_current_blog_id(), 'sm_options' )['sm_b_sitemap_name'])) {
					$sm_sitemap_name = get_blog_option( get_current_blog_id(), 'sm_options' )['sm_b_sitemap_name'];
				}
			} else if(isset(get_option('sm_options')['sm_b_sitemap_name'])) $sm_sitemap_name = get_option('sm_options')['sm_b_sitemap_name'];
			if(!isset($sm_sitemap_name)) $sm_sitemap_name = 'sitemap';
			$gsg->add_url(
				str_replace('.html', $sm_sitemap_name . '.html', $gsg->get_xml_url( 'main', '', array( 'html' => true ) ) ),
				( $lm ? $gsg->get_timestamp_from_my_sql( $lm ) : time() )
			);
		}

		do_action( 'sm_buildmap' );
	}

	/**
	 * Generates the author sitemap
	 *
	 * @param GoogleSitemapGenerator $gsg instence of sitemap generator class.
	 */
	public function build_authors( $gsg ) {
		/**
		 * Use the wpdb global variable
		 *
		 * @var $wpdb wpdb
		 * */
		global $wpdb;

		// Unfortunately there is no API function to get all authors, so we have to do it the dirty way...
		// We retrieve only users with published and not password protected enabled post types.

		$enabled_post_types = null;
		$enabled_post_types = $gsg->get_active_post_types();

		// Ensure we count at least the posts...
		$enabled_post_types_count = count( $enabled_post_types );
		if ( 0 === $enabled_post_types_count ) {
			$enabled_post_types[] = 'post';
		}
		$sql     = "SELECT DISTINCT
						u.ID,
						u.user_nicename,
						MAX(p.post_modified_gmt) AS last_post
					FROM
						{$wpdb->users} u,
						{$wpdb->posts} p
					WHERE
						p.post_author = u.ID
						AND p.post_status = 'publish'
						AND p.post_type IN(" . implode( ', ', array_fill( 0, count( $enabled_post_types ), '%s' ) ) . ")
						AND p.post_password = ''
					GROUP BY
						u.ID,
						u.user_nicename";
		$query   = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $enabled_post_types ) );
		$authors = $wpdb->get_results( $query ); // phpcs:ignore

		if ( $authors && is_array( $authors ) ) {
			$authors = $this->exclude_authors( $authors );

			if ( ! empty( $authors ) ) {
				foreach ( $authors as $author ) {
					$url = get_author_posts_url( $author->ID, $author->user_nicename );
					$gsg->add_url(
						$url,
						$gsg->get_timestamp_from_my_sql( $author->last_post ),
						$gsg->get_option( 'cf_auth' ),
						$gsg->get_option( 'pr_auth' )
					);
				}
			}
		}
	}

	/**
	 * Wrap legacy filter to deduplicate calls.
	 *
	 * @param array $users Array of user objects to filter.
	 *
	 * @return array
	 */
	protected function exclude_authors( $authors ) {

		/**
		 * Filter the authors, included in XML sitemap.
		 *
		 * @param array $authors Array of user objects to filter.
		 */
		return apply_filters( 'sm_sitemap_exclude_author', $authors );
	}

	/**
	 * Filters the terms query to only include published posts
	 *
	 * @param string[] $selects Array of string.
	 * @return string[]
	 */
	public function filter_terms_query( $selects ) {
		/**
		 * Global variable in functional scope for database
		 *
		 * @var wpdb $wpdb  Global variable for wpdb
		 */
		global $wpdb;
		$selects[] = "
		( /* ADDED BY XML SITEMAPS */
			SELECT
				UNIX_TIMESTAMP(MAX(p.post_date_gmt)) as _mod_date
			FROM
				{$wpdb->posts} p,
				{$wpdb->term_relationships} r
			WHERE
				p.ID = r.object_id
				AND p.post_status = 'publish'
				AND p.post_password = ''
				AND r.term_taxonomy_id = tt.term_taxonomy_id
		) as _mod_date
		 /* END ADDED BY XML SITEMAPS */
		";

		return $selects;
	}

	/**
	 * Generates the taxonomies sitemap
	 *
	 * @param GoogleSitemapGenerator $gsg Instance of sitemap generator.
	 * @param string                 $taxonomy The Taxonomy.
	 */
	public function build_taxonomies( $gsg, $taxonomy ) {

		$offset         = $taxonomy;
		$links_per_page = $gsg->get_entries_per_page();
		if ( gettype( $links_per_page ) !== 'integer' ) {
			$links_per_page = (int)1000;
		}
		if ( strpos( $taxonomy, '-' ) !== false ) {
			$offset   = substr( $taxonomy, strrpos( $taxonomy, '-' ) + 1 );
			$taxonomy = str_replace( '-' . $offset, '', $taxonomy );
		} else {
			$offset = 1;
		}
		$temp_offset = $offset;
		$offset = intval( $offset );
		if ( 0 === $offset ) {
			$taxonomy = $taxonomy . '-' . $temp_offset;
			$links_per_page = $this->linkPerPage;
		} else {
			$offset = ( --$offset ) * $links_per_page;
		}
		$enabled_taxonomies = $this->get_enabled_taxonomies( $gsg );
		if ( in_array( $taxonomy, $enabled_taxonomies, true ) ) {

			$excludes = array();

			$excl_cats = $gsg->get_option( 'b_exclude_cats' ); // Excluded cats.
			if ( $excl_cats ) {
				$excludes = $excl_cats;
			}
			add_filter( 'get_terms_fields', array( $this, 'filter_terms_query' ), 20, 2 );
			/*
			$terms = get_terms(
				$taxonomy,
				array(
					'number'       => $links_per_page,
					'offset'       => $offset,
					'hide_empty'   => true,
					'hierarchical' => false,
					'exclude'      => $excludes,
				)
			);
			*/
			$queryArr = [
				'taxonomy'		=> $taxonomy,
				'number'		=> $links_per_page,
				'offset'		=> $offset,
				'exclude'		=> $excludes,
			];
			$queryArr['hide_empty'] = apply_filters( 'sm_sitemap_taxonomy_hide_empty', true );
			if (preg_match('/(post_tag|category)/', $taxonomy)) {
				$queryArr['hierarchical'] = false;
			}
			$terms = array_values(
				array_unique(
					array_filter(
						$this->get_terms($gsg, $queryArr),
						function ($term) use ($taxonomy) {
							return $term->taxonomy === $taxonomy;
						}
					),
					SORT_REGULAR
				)
			);

			remove_filter( 'get_terms_fields', array( $this, 'filter_terms_query' ), 20, 2 );

			//$terms = array_values(array_unique($terms, SORT_REGULAR));

			/**
			 * Filter: 'sm_exclude_from_sitemap_by_term_ids' - Allow excluding terms by ID.
			 *
			 * @param array $terms_to_exclude The terms to exclude.
			 */
			$terms_to_exclude = apply_filters( 'sm_exclude_from_sitemap_by_term_ids', [] );

			$step          = 1;
			$size_of_terms = count( $terms );
			for ( $tax_count = 0; $tax_count < $size_of_terms; $tax_count++ ) {
				$term = $terms[ $tax_count ];

				if ( in_array( $term->term_id, $terms_to_exclude ) ) {
					$step++;
					continue;
				}

				$images = [];
				if ( $this->include_images ) {
					$images = $this->get_image_parser()->get_term_images( $term );
				}

				$term_link = apply_filters( 'sm_build_taxonomy_link', get_term_link( $term, $step ), $term->taxonomy );

				switch ( $term->taxonomy ) {
					case 'category':
						$gsg->add_url( $term_link, $this->getTaxonomyUpdatedDate($term->term_id) ?: 0, $gsg->get_option( 'cf_cats' ), $gsg->get_option( 'pr_cats' ), $images );
						break;
					case 'product_cat':
						$gsg->add_url( $term_link, $term->_mod_date, $gsg->get_option( 'cf_product_cat' ), $gsg->get_option( 'pr_product_cat' ), $images );
						break;
					case 'post_tag':
						$gsg->add_url( $term_link, $this->getTaxonomyUpdatedDate($term->term_id) ?: 0, $gsg->get_option( 'cf_tags' ), $gsg->get_option( 'pr_tags' ), $images );
						break;
					default:
						$gsg->add_url( $term_link, $this->getTaxonomyUpdatedDate($term->term_id) ?: 0, $gsg->get_option( 'cf_' . $term->taxonomy ), $gsg->get_option( 'pr_' . $term->taxonomy ), $images );
						break;
				}
				$step++;
			}
		}
	}

	/*
		get last updated date of taxonomy post
		returns timestamp (int)
	*/
	private function getTaxonomyUpdatedDate($term_id){
		global $wpdb;

		$query = $wpdb->prepare("
			SELECT p.*
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.term_id = %d
			ORDER BY p.post_date DESC
			LIMIT 1
		", $term_id);

		$post = $wpdb->get_row($query);

		if ($post) {
			return strtotime($post->post_date);
		}
	}

	/**
	 * Returns the enabled taxonomies. Only taxonomies with posts are returned.
	 *
	 * @param GoogleSitemapGenerator $gsg Google sitemap generator's instance.
	 * @return array
	 */
	public function get_enabled_taxonomies( GoogleSitemapGenerator $gsg ) {
		$enabled_taxonomies = $gsg->get_option( 'in_tax' );
		if ( $gsg->get_option( 'in_tags' ) ) {
			$enabled_taxonomies[] = 'post_tag';
		}
		if ( $gsg->get_option( 'in_cats' ) ) {
			$enabled_taxonomies[] = 'category';
		}
		return $enabled_taxonomies;
	}

	/**
	 * Returns the enabled Product tags. Only Product Tags with posts are returned.
	 *
	 * @param GoogleSitemapGenerator $gsg Instance of sitemap generator.
	 * @param int                    $offset Offset.
	 * @return void
	 */
	public function build_product_tags( GoogleSitemapGenerator $gsg, $offset ) {
		$links_per_page = $gsg->get_entries_per_page();
		if ( gettype( $links_per_page ) !== 'integer' ) {
			$links_per_page = (int) 1000;
		}
		$offset = (intval(--$offset)) * $links_per_page;

		add_filter( 'get_terms_fields', array( $this, 'filter_terms_query' ), 20, 2 );
		$terms = get_terms(
			'product_tag',
			array(
				'number' => $links_per_page,
				'offset' => $offset,
				'order' => $gsg->get_sitemap_sort_order(),
			)
		);
		remove_filter( 'get_terms_fields', array( $this, 'filter_terms_query' ), 20, 2 );
		$term_array = array();

		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$term_array[] = $term->name;
				$url          = get_term_link( $term );
				//$gsg->add_url( $url, $term->_mod_date, $gsg->get_option( 'cf_tags' ), $gsg->get_option( 'pr_tags' ), $term->ID, array(), array(), '' );
				$gsg->add_url( $url, $this->getProductUpdatedDate($term->term_id, 'product_tag'), $gsg->get_option( 'cf_tags' ), $gsg->get_option( 'pr_tags' ), $term->ID, array(), array(), '' );
			}
		}
	}

	/**
	 * Returns the enabled Product Categories. Only Product Categories with posts are returned.
	 *
	 * @param GoogleSitemapGenerator $gsg Instance of sitemap generator.
	 * @param int                    $offset Offset.
	 * @return void
	 */
	public function build_product_categories( GoogleSitemapGenerator $gsg, $offset ) {
		$links_per_page = $gsg->get_entries_per_page();
		if ( gettype( $links_per_page ) !== 'integer' ) {
			//$links_per_page = (int) 1000;
			$links_per_page = (int)$links_per_page;
		}
		$offset = (intval(--$offset)) * $links_per_page;
		$excludes       = array();
		$excl_cats      = $gsg->get_option( 'b_exclude_cats' ); // Excluded cats.
		if ( $excl_cats ) {
			$excludes = $excl_cats;
		}
		add_filter( 'get_terms_fields', array( $this, 'filter_terms_query' ), 20, 2 );
		$category = get_terms(
			'product_cat',
			array(
				'number'  	=> $links_per_page,
				'offset'  	=> $offset,
				'exclude' 	=> $excludes,
				'order' 	=> $gsg->get_sitemap_sort_order(),
			)
		);
		remove_filter( 'get_terms_fields', array( $this, 'filter_terms_query' ), 20, 2 );
		$cat_array = array();
		if ( ! empty( $category ) && ! is_wp_error( $category ) ) {
			$step = 1;
			foreach ( $category as $cat ) {
				$cat_array[] = $cat->name;
				if ( $cat && wp_count_terms( $cat->name, array( 'hide_empty' => true ) ) > 0 ) {
					$step++;
					$url = get_term_link( $cat );
					$images = [];
					if ( $this->include_images ) {
						$images = $this->get_image_parser()->get_term_images( $cat );
					}
					$gsg->add_url( $url, $this->getProductUpdatedDate($cat->term_id, 'product_cat'), $gsg->get_option( 'cf_product_cat' ), $gsg->get_option( 'pr_product_cat' ), $images, $cat->ID, array(), array(), '' );
				}
			}
		}
	}

	/* Get last product updated date by tag ID */
	private function getProductUpdatedDate($term_id, $taxonomy){
		$args = array(
			'post_type' => 'product',
			'posts_per_page' => 1,
			'tax_query' => array(
				array(
					'taxonomy' => $taxonomy,
					'field' => 'id',
					'terms' => $term_id,
				),
			),
			'orderby' => 'modified',
			'order' => 'DESC',
		);

		$products = new WC_Product_Query($args);
		$product_results = $products->get_products();

		if ($product_results) {
			$product = array_shift($product_results);
			$updated_date = strtotime($product->get_date_modified()->date('Y-m-d H:i:s'));

			return $updated_date;
		}
		return false;
	}

	/**
	 * Generates the external sitemap
	 *
	 * @param GoogleSitemapGenerator $gsg Instance of sitemap generator.
	 */
	public function build_externals( $gsg ) {
		$pages = $gsg->get_pages();
		if ( $pages && is_array( $pages ) && count( $pages ) > 0 ) {
			foreach ( $pages as $page ) {
				// Disabled phpcs for backward compatibility .
				// phpcs:disable
				$url         = ! empty( $page->get_url() ) ? $page->get_url() : $page->url;
				$change_freq = ! empty( $page->get_change_freq() ) ? $page->get_change_freq() : $page->change_freq;
				$priority    = ! empty( $page->get_priority() ) ? $page->get_priority() : $page->priority;
				$last_mod    = ! empty( $page->get_last_mod() ) ? $page->get_last_mod() : $page->last_mod;
				// phpcs:enable
				/**
				 * Description for $page variable.
				 *
				 * @var $page GoogleSitemapGeneratorPage
				 */
				$gsg->add_url( $url, $last_mod, $change_freq, $priority );
			}
		}
	}

	/**
	 * Generates the sitemap index
	 *
	 * @param GoogleSitemapGenerator $gsg Instance of sitemap generator.
	 */
	public function index( $gsg ) {
		/**
		 * Global variable for database.
		 *
		 * @var $wpdb wpdb
		 */
		global $wpdb;
		$blog_update    = strtotime( get_lastpostmodified( 'gmt' ) );
		$links_per_page = $gsg->get_entries_per_page();
		if ( 0 === $links_per_page || is_nan( $links_per_page ) ) {
			$links_per_page = $this->linkPerPage;
			$gsg->set_option( 'links_page', $this->linkPerPage );
		}
		else if ($links_per_page > $this->maxLinksPerPage) $links_per_page = $this->maxLinksPerPage;
		$gsg->add_sitemap( 'misc', null, $blog_update );

		/**
		 * Filter: 'sm_sitemap_exclude_taxonomy' - Allow extending and modifying the taxonomies to exclude.
		 *
		 * @param array $taxonomies_to_exclude The taxonomies to exclude.
		 */
		$taxonomies_to_exclude = [];
		$default_taxonomies_to_exclude = [ 'product_tag', 'product_cat' ];
		$taxonomies_to_exclude = apply_filters( 'sm_sitemap_exclude_taxonomy', $taxonomies_to_exclude );
		if ( ! is_array( $taxonomies_to_exclude ) || empty( $taxonomies_to_exclude ) ) {
			$taxonomies_to_exclude = $default_taxonomies_to_exclude;
		} else {
			$taxonomies_to_exclude = array_merge( $taxonomies_to_exclude, $default_taxonomies_to_exclude );
		}
		$enabled_taxonomies = $this->get_enabled_taxonomies( $gsg );
		$excl_cats = $gsg->get_option( 'b_exclude_cats' );
		$excludes = $excl_cats ? $excl_cats : array();
		$terms_by_taxonomy = array();

		foreach ( $enabled_taxonomies as $taxonomy ) {
			if ( ! in_array( $taxonomy, $taxonomies_to_exclude, true ) ) {
				$terms_args = [
					'taxonomy' => $taxonomy,
					'exclude' => $excludes
				];
				$terms_args['hide_empty'] = apply_filters( 'sm_sitemap_taxonomy_hide_empty', true );
				$terms = $this->get_terms( $gsg, $terms_args );
				$terms_by_taxonomy[ $taxonomy ] = $terms;
			}
		}

		foreach ( $terms_by_taxonomy as $taxonomy => $terms ) {
			$step = 1;
			$i = 0;
			foreach ( $terms as $term ) {
				if ( 0 === ( $i % $links_per_page ) && '' !== $term->taxonomy && taxonomy_exists( $term->taxonomy ) ) {
					$gsg->add_sitemap( $term->taxonomy,'-sitemap' . ($step === 1? '' : $step), $blog_update );
					$step++;
				}
				$i++;
			}
		}

		// If Product Tags is enabled from sitemap settings.
		if ( true === $gsg->get_option( 'product_tags' ) ) {
			$product_tags = get_terms( 'product_tag' );
			if ( ! empty( $product_tags ) && ! is_wp_error( $product_tags ) ) {
				$step                 = 1;
				$product_tags_size_of = count( $product_tags );

				for ( $product_count = 0; $product_count < $product_tags_size_of; $product_count++ ) {
					if ( 0 === ( $product_count % $links_per_page ) ) {
						//$gsg->add_sitemap( 'producttags', $step, $blog_update );
						$gsg->add_sitemap( 'producttags', '-sitemap' . ($step === 1? '' : $step), $blog_update );
						$step = ++$step;
					}
				}
			}
		}

		// If Product category is enabled from sitemap settings.
		if ( true === $gsg->get_option( 'in_product_cat' ) ) {
			$excludes  = array();
			$excl_cats = $gsg->get_option( 'b_exclude_cats' ); // Excluded cats.

			if ( $excl_cats ) {
				$excludes = $excl_cats;
			}

			$product_cat = get_terms( 'product_cat', array( 'exclude' => $excludes ) );

			if ( ! empty( $product_cat ) && ! is_wp_error( $product_cat ) ) {
				$step              = 1;
				$product_cat_count = count( $product_cat );
				for ( $product_count = 0; $product_count < $product_cat_count; $product_count++ ) {
					if ( 0 === ( $product_count % $links_per_page ) ) {
						//$gsg->add_sitemap( 'productcat', $step, $blog_update );
						$gsg->add_sitemap( 'productcat', '-sitemap' . ($step === 1? '' : $step), $blog_update );
						$step = ++$step;
					}
				}
			}
		}

		$pages = (array)$gsg->get_pages();
		if ( count( $pages ) > 0 ) {
			foreach ( $pages as $page ) {
				if ( $page instanceof GoogleSitemapGeneratorPage ) {
					$url = ! empty( $page->get_url() ) ? $page->get_url() : ( property_exists( $page, '_url' ) ? $page->_url : '' );
					if ( $url ) {
						$gsg->add_sitemap( 'externals-sitemap', null, $blog_update );
						break;
					}
				}
			}
		}

		$enabled_post_types = $gsg->get_active_post_types();

		//checking for products enabled
		if($gsg->get_option( 'in_product_assortment' ) !== null && $gsg->get_option( 'in_product_assortment' ) !== true){
			$enabled_post_types = array_filter($enabled_post_types, function($value) {
				return $value !== 'product';
			});
		}

		$has_enabled_post_types_posts = false;
		$has_posts                    = false;

		if ( count( $enabled_post_types ) > 0 ) {

			$excluded_post_ids = $gsg->get_excluded_post_ids( $gsg );
			$not_allowed_slugs = $gsg->robots_disallowed();
			$excluded_post_ids = array_unique( array_merge( $excluded_post_ids, $not_allowed_slugs ), SORT_REGULAR );
			$gsg->set_option( 'b_exclude', $excluded_post_ids );
			$gsg->save_options();
			$ex_post_s_q_l           = '';
			$excluded_post_ids_count = count( $excluded_post_ids );
			if ( $excluded_post_ids_count > 0 ) {
				$ex_post_s_q_l = 'AND p.ID NOT IN (' . implode( ',', $excluded_post_ids ) . ')';
			}
			$excluded_category_i_d_s       = $gsg->get_excluded_category_i_ds( $gsg );
			$ex_cat_s_q_l                  = '';
			$excluded_category_i_d_s_count = count( $excluded_category_i_d_s );
			if ( $excluded_category_i_d_s_count > 0 ) {
				$ex_cat_s_q_l = "AND ( p.ID NOT IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (" . implode( ',', $excluded_category_i_d_s ) . ')))';
			}
			foreach ( $enabled_post_types as $post_type_custom ) {
				// phpcs:disable
				$prp = $wpdb->prepare(
					"SELECT
					COUNT(p.ID) AS `numposts`,
					MAX(p.post_modified_gmt) as `last_mod`
					FROM
						{$wpdb->posts} p
					WHERE
						p.post_password = ''
						AND p.post_type = '%s'
						AND p.post_status = 'publish'
						" . $ex_post_s_q_l . ""
						. $ex_cat_s_q_l . "
					ORDER BY
						p.post_date_gmt DESC",
						$post_type_custom
				);
				$posts = $wpdb->get_results($prp);

				if ( $posts ) {
					if ( 'post' === $post_type_custom ) {
						$has_posts = true;
					}
					$has_enabled_post_types_posts = true;

					foreach ( $posts as $post ) {
						$step = 1;
						for ( $i = 0; $i < $post->numposts; $i++ ) {
							if ( 0 === ( $i % $links_per_page ) ) {
								//$gsg->add_sitemap( 'pt', $post_type_custom . '-p' . $step . '-' . sprintf( '%04d-%02d', $post->year, $post->month ), $gsg->get_timestamp_from_my_sql( $post->last_mod ), 'p' . $step );
								$gsg->add_sitemap( 'pt', $post_type_custom . '-sitemap' . ($step === 1? '' : $step) , $gsg->get_timestamp_from_my_sql( $post->last_mod ) );
								$step = ++$step;
							}
						}
						// $gsg->add_sitemap( 'pt', $post_type_custom . '-' . sprintf( '%04d-%02d', $post->year, $post->month ), $gsg->get_timestamp_from_my_sql( $post->last_mod ) );
					}
				}
				// phpcs:enable
			}
		}

		// Only include authors if there is a public post with a enabled post type.
		if ( $gsg->get_option( 'in_auth' ) && $has_enabled_post_types_posts ) {
			$gsg->add_sitemap( 'authors-sitemap', null, $blog_update );
		}

		// Only include archived if there are posts with postType post.
		if ( $gsg->get_option( 'in_arch' ) && $has_posts ) {
			$gsg->add_sitemap( 'archives-sitemap', null, $blog_update );
		}

		/**
		 * Filter: 'sm_sitemap_index' - Allow extending and modifying the xml links to include.
		 *
		 * @param array $sitemap_custom_items The custom xml link to include.
		 */
		$sitemap_custom_items = [];
		$sitemap_custom_items = apply_filters( 'sm_sitemap_index', $sitemap_custom_items );
		if ( ! is_array( $sitemap_custom_items ) ) {
			$sitemap_custom_items = [];
		}
		if ( ! empty( $sitemap_custom_items ) ) {
			foreach ( $sitemap_custom_items as $sitemap_custom_item ) {
				$title = ( isset( $sitemap_custom_item['title'] ) ) ? $sitemap_custom_item['title'] : '';
				$modified = ( isset( $sitemap_custom_item['modified'] ) ) ? strtotime( $sitemap_custom_item['modified'] ) : '';
				if ( $title != '' && $modified != '' ) {
					$gsg->add_sitemap( $title, null, $modified );
				}
			}
		}
	}

	/**
	 * Return the URL to the sitemap related to a specific post
	 *
	 * @param array                  $urls Post sitemap urls.
	 * @param GoogleSitemapGenerator $gsg Instance of google sitemap generator.
	 * @param int                    $post_id The post ID.
	 *
	 * @return string[]
	 */
	public function get_sitemap_url_for_post( array $urls, $gsg, $post_id ) {
		$post = get_post( $post_id );
		if ( $post ) {
			$last_modified = $gsg->get_timestamp_from_my_sql( $post->post_modified_gmt );

			$url    = $gsg->get_xml_url( 'pt', $post->post_type . '-' . gmdate( 'Y-m', $last_modified ) );
			$urls[] = $url;
		}

		return $urls;
	}

	public function get_terms( $gsg, $args = [] ) {
		global $wpdb;

		$taxonomy = ( isset( $args['taxonomy'] ) && null !== $args['taxonomy'] ) ? $args['taxonomy'] : false;

		$sql = 'SELECT DISTINCT *';
		$sql .= ' FROM '.$wpdb->prefix.'terms as t';
		$sql .= ' INNER JOIN '.$wpdb->prefix.'term_taxonomy as tt';
		$sql .= ' WHERE `tt`.taxonomy = \'' . $taxonomy . '\'';
		$sql .= ' AND `tt`.term_id = `t`.term_id';

		if ( ! empty( $args ) ) {
			if ( isset( $args['hide_empty'] ) && $args['hide_empty'] === true ) {
				$sql .= ' AND `tt`.count != 0';
			}
			if ( isset( $args['hierarchical'] ) && $args['hierarchical'] === true ) {
				$sql .= ' AND `tt`.parent != 0';
			}
			if ( isset( $args['exclude'] ) && is_array( $args['exclude'] ) && ! empty( $args['exclude'] ) ) {
				foreach ( $args['exclude'] as $term_id ) {
					$sql .= ' AND `tt`.term_id != ' . $term_id;
				}
			}
			$sort_order = $gsg->get_sitemap_sort_order();
			$sql .= ' ORDER BY t.name ' . $sort_order;
			if ( isset( $args['number'] ) && $args['number'] != '' ) {
				$sql .= ' LIMIT ' . $args['number'];
			}
			if ( isset( $args['offset'] ) && $args['offset'] != ''  ) {
				$sql .= ' OFFSET ' . $args['offset'];
			}
		}

		$result = $wpdb->get_results($sql);

		return $result;
	}
}

if ( defined( 'WPINC' ) ) {
	new GoogleSitemapGeneratorStandardBuilder();
}
