<?php
/**
 * Registers the Resource post type and its taxonomies.
 *
 * One post type holds everything; the Resource Type decides the section
 * (see NH_Sections) and therefore the address:
 *   /knowledge/{slug}/             articles
 *   /legal-case-insights/{slug}/   case studies
 *   /faq/{slug}/                   FAQs
 * Section, topic and search pages are custom rules, see NH_Rewrite.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Post_Types {

	const CPT            = 'nh_resource';
	const TAX_TYPE       = 'nh_type';
	const TAX_TOPIC      = 'nh_topic';
	const TAX_CASE_TOPIC = 'nh_case_topic';
	const TAX_TAG        = 'nh_tag';
	const TAX_FAQ_GROUP  = 'nh_faq_group'; // Groups of FAQ items, for [nh_faq group="…"]. No pages of its own.
	const BASE           = 'knowledge';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 99 );
	}

	/** Taxonomies whose terms belong to hub resources. */
	public static function taxonomies() {
		return array_merge( array( self::TAX_TYPE, self::TAX_TOPIC, self::TAX_CASE_TOPIC, self::TAX_TAG, self::TAX_FAQ_GROUP ), array_keys( NH_Sections::topic_groups() ) );
	}

	public static function register() {
		// Taxonomies are registered BEFORE the post type on purpose: the tag rewrite rules
		// (knowledge/tag/x) must come before the post type's attachment rules
		// (knowledge/{post}/{attachment}), otherwise /knowledge/tag/payroll/ would 404.
		NH_Sections::register_topic_groups(); // Own topic groups made in Settings → Sections.
		register_taxonomy(
			self::TAX_FAQ_GROUP,
			self::CPT,
			array(
				'labels'            => array(
					'name'          => __( 'FAQ Groups', 'neurohr-hub' ),
					'singular_name' => __( 'FAQ Group', 'neurohr-hub' ),
					'add_new_item'  => __( 'Add New FAQ Group', 'neurohr-hub' ),
					'menu_name'     => __( 'FAQ Groups', 'neurohr-hub' ),
				),
				'description'       => __( 'Only for FAQ items. Show a group anywhere with [nh_faq group="slug"].', 'neurohr-hub' ),
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => false,
				'rewrite'           => false,
			)
		);

		register_taxonomy(
			self::TAX_TYPE,
			self::CPT,
			array(
				'labels'            => array(
					'name'          => __( 'Resource Types', 'neurohr-hub' ),
					'singular_name' => __( 'Resource Type', 'neurohr-hub' ),
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => 'resource_type',
				'rewrite'           => false,
			)
		);

		register_taxonomy(
			self::TAX_TOPIC,
			self::CPT,
			array(
				'labels'            => array(
					'name'          => __( 'HR Topics', 'neurohr-hub' ),
					'singular_name' => __( 'HR Topic', 'neurohr-hub' ),
					'menu_name'     => __( 'HR Topics', 'neurohr-hub' ),
				),
				'description'       => __( 'Topics for articles (and later templates and videos).', 'neurohr-hub' ),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => 'resource_topic',
				'rewrite'           => false,
			)
		);

		register_taxonomy(
			self::TAX_CASE_TOPIC,
			self::CPT,
			array(
				'labels'            => array(
					'name'          => __( 'Case Topics', 'neurohr-hub' ),
					'singular_name' => __( 'Case Topic', 'neurohr-hub' ),
					'menu_name'     => __( 'Case Topics', 'neurohr-hub' ),
				),
				'description'       => __( 'Topics for Legal Case Insights.', 'neurohr-hub' ),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => 'case_topic',
				'rewrite'           => false,
			)
		);

		register_taxonomy(
			self::TAX_TAG,
			self::CPT,
			array(
				'labels'            => array(
					'name'          => __( 'Tags', 'neurohr-hub' ),
					'singular_name' => __( 'Tag', 'neurohr-hub' ),
				),
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => 'resource_tag',
				'rewrite'           => array(
					'slug'       => self::BASE . '/tag',
					'with_front' => false,
				),
			)
		);

		// Section rules must come before the archive rule register_post_type() adds for
		// /knowledge/, otherwise WordPress's plain archive would answer /knowledge/ instead of the hub.
		NH_Rewrite::add_rules();

		register_post_type(
			self::CPT,
			array(
				'labels'        => array(
					'name'          => __( 'Knowledge Hub', 'neurohr-hub' ),
					'singular_name' => __( 'Resource', 'neurohr-hub' ),
					'add_new_item'  => __( 'Add New Resource', 'neurohr-hub' ),
					'edit_item'     => __( 'Edit Resource', 'neurohr-hub' ),
					'all_items'     => __( 'All Resources', 'neurohr-hub' ),
					'search_items'  => __( 'Search Resources', 'neurohr-hub' ),
					'not_found'     => __( 'No resources found.', 'neurohr-hub' ),
				),
				'public'        => true,
				'has_archive'   => self::BASE, // Keeps /knowledge/feed/ (all sections) for MailerLite.
				'rewrite'       => array(
					'slug'       => self::BASE,
					'with_front' => false,
				),
				'menu_icon'     => 'dashicons-book-alt',
				'menu_position' => 25,
				'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
				'taxonomies'    => self::taxonomies(),
				'show_in_rest'  => true,
			)
		);
	}

	/**
	 * The resource types a new site starts with. Since 0.12 they can be renamed (name and
	 * slug); the hub knows them by ID and by their behaviour (NH_Types).
	 * (News and regulations are ordinary WordPress posts, not hub resources.)
	 */
	public static function default_types() {
		return array(
			'article'    => 'Article',
			'case-study' => 'Case Study',
			'faq'        => 'FAQ',
			'video'      => 'Video',
			'download'   => 'Download',
		);
	}

	public static function activate() {
		self::register();
		self::ensure_types();
		NH_Upgrade::run( (string) get_option( 'nh_hub_version', '' ) );
		NH_Rewrite::rebuild_slugs();
		update_option( 'nh_hub_version', NH_HUB_VERSION, false );
		NH_Rewrite::add_rules();
		flush_rewrite_rules();
	}

	/** One type of every behaviour must exist; a renamed type counts (no empty copies). */
	public static function ensure_types() {
		foreach ( array_keys( NH_Types::kinds() ) as $kind ) {
			NH_Types::find_or_create( $kind );
		}
	}

	/**
	 * Uploading a new zip over an active plugin does not run the activation hook,
	 * so run the same setup once whenever the version changes.
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( 'nh_hub_version', '' );
		if ( NH_HUB_VERSION === $installed ) {
			return;
		}
		self::ensure_types();
		NH_Upgrade::run( $installed );
		NH_Rewrite::rebuild_slugs();
		update_option( 'nh_hub_version', NH_HUB_VERSION, false );
		// Rules for topics created just now are added and saved by NH_Rewrite::maybe_flush().
		update_option( NH_Rewrite::OPTION_FLUSH, 1, true );
	}
}
