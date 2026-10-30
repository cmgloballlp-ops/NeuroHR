<?php
/**
 * Import a BetterDocs export (WordPress XML, Tools → Export → Docs) into the Knowledge Hub.
 *
 * - Creates new Resources with the same slugs, so /knowledge/{slug}/ URLs keep working.
 * - Articles get HR topics, cases get Case Topics (the old legal categories), see NH_Upgrade.
 * - Carries over excerpts, view counts, related articles and attachments.
 * - Resources whose slug already exists are skipped, so the import can be run again safely.
 * - BetterDocs FAQs (platform introduction) are imported as Free (every logged-in member).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Import_BetterDocs {

	const ACTION    = 'nh_import_betterdocs';
	const REPORT    = 'nh_hub_import_report';
	const MAX_BYTES = 31457280; // 30 MB.

	const NS_WP      = 'http://wordpress.org/export/1.2/';
	const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
	const NS_EXCERPT = 'http://wordpress.org/export/1.2/excerpt/';

	/* ---------- Topic plan (see NH_Upgrade, shared with the upgrade routine) ---------- */

	/** All topic names by slug, HR and case topics together (for the preview). */
	public static function topic_names() {
		return array_merge( NH_Upgrade::hr_topics(), NH_Upgrade::case_topics() );
	}

	/** Articles in this old category also get the SME tag ("HR for SMEs" became a tag). */
	const SME_CATEGORY = 'hr-for-smes';
	const SME_TAG      = 'sme';

	/* ---------- Admin page ---------- */

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . NH_Post_Types::CPT,
			__( 'Import from BetterDocs', 'neurohr-hub' ),
			__( 'Import BetterDocs', 'neurohr-hub' ),
			'manage_options',
			'nh-hub-import',
			array( __CLASS__, 'render_page' )
		);
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . NH_Post_Types::CPT . '&page=nh-hub-import' ) );
	}

	private static function transient_key() {
		return 'nh_hub_import_' . get_current_user_id();
	}

	/* ---------- Parsing ---------- */

	/**
	 * Parse a WordPress export file. Returns array( 'categories', 'tags', 'items' ) or WP_Error.
	 */
	public static function parse( $xml ) {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return new WP_Error( 'nh_no_xml', __( 'The SimpleXML PHP extension is required.', 'neurohr-hub' ) );
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $doc || ! isset( $doc->channel ) ) {
			return new WP_Error( 'nh_bad_xml', __( 'This file is not a WordPress export (XML) file.', 'neurohr-hub' ) );
		}

		$channel = $doc->channel;
		$wp      = $channel->children( self::NS_WP );
		$data    = array( 'categories' => array(), 'tags' => array(), 'items' => array() );

		foreach ( $wp->term as $term ) {
			$taxonomy = (string) $term->term_taxonomy;
			$slug     = sanitize_title( (string) $term->term_slug );
			$name     = html_entity_decode( (string) $term->term_name, ENT_QUOTES, 'UTF-8' );
			if ( 'doc_category' === $taxonomy ) {
				$image = 0;
				foreach ( $term->termmeta as $meta ) {
					if ( 'doc_category_image-id' === (string) $meta->meta_key ) {
						$image = absint( (string) $meta->meta_value );
					}
				}
				$data['categories'][ $slug ] = array(
					'name'        => $name,
					'description' => (string) $term->term_description,
					'image'       => $image,
				);
			} elseif ( 'doc_tag' === $taxonomy ) {
				$data['tags'][ $slug ] = $name;
			}
		}

		foreach ( $channel->item as $item ) {
			$w         = $item->children( self::NS_WP );
			$post_type = (string) $w->post_type;
			if ( ! in_array( $post_type, array( 'docs', 'betterdocs_faq' ), true ) ) {
				continue;
			}
			$row = array(
				'old_id'     => (int) $w->post_id,
				'post_type'  => $post_type,
				'title'      => (string) $item->title,
				'slug'       => sanitize_title( (string) $w->post_name ),
				'status'     => (string) $w->status,
				'date'       => (string) $w->post_date,
				'date_gmt'   => (string) $w->post_date_gmt,
				'menu_order' => (int) $w->menu_order,
				'content'    => (string) $item->children( self::NS_CONTENT )->encoded,
				'excerpt'    => (string) $item->children( self::NS_EXCERPT )->encoded,
				'categories' => array(),
				'tags'       => array(),
				'kbs'        => array(),
				'meta'       => array(),
			);
			foreach ( $item->category as $cat ) {
				$domain = (string) $cat['domain'];
				$slug   = sanitize_title( (string) $cat['nicename'] );
				if ( 'doc_category' === $domain ) {
					$row['categories'][] = $slug;
				} elseif ( 'doc_tag' === $domain ) {
					$row['tags'][] = $slug;
				} elseif ( 'knowledge_base' === $domain ) {
					$row['kbs'][] = $slug;
				}
			}
			foreach ( $w->postmeta as $meta ) {
				$key = (string) $meta->meta_key;
				if ( in_array( $key, array( '_betterdocs_meta_views', '_betterdocs_related_articles', '_betterdocs_attachments' ), true ) ) {
					$row['meta'][ $key ] = (string) $meta->meta_value;
				}
			}
			$data['items'][] = $row;
		}

		return $data;
	}

	/**
	 * BetterDocs stores related articles and attachments as a PHP-serialized array of JSON strings.
	 * allowed_classes => false: never instantiate objects from file content.
	 */
	private static function decode_json_list( $raw ) {
		if ( '' === $raw || ! is_serialized( $raw ) ) {
			return array();
		}
		$list = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $json ) {
			$decoded = is_string( $json ) ? json_decode( $json, true ) : null;
			if ( is_array( $decoded ) ) {
				$out[] = $decoded;
			}
		}
		return $out;
	}

	/**
	 * Decide what happens to each item. Used for the preview and the import.
	 */
	public static function plan( $data ) {
		$hr_map    = NH_Upgrade::hr_category_map();
		$case_map  = NH_Upgrade::case_category_map();
		$overrides = NH_Upgrade::post_overrides();
		$rows      = array();

		foreach ( $data['items'] as $item ) {
			if ( ! in_array( $item['status'], array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
				continue;
			}

			if ( 'betterdocs_faq' === $item['post_type'] ) {
				$type = 'faq';
			} elseif ( in_array( 'legal-case-insights', $item['kbs'], true ) ) {
				$type = 'case-study';
			} else {
				$type = 'article';
			}

			// Cases get case topics (legal categories); articles get HR topics. FAQs have none.
			$taxonomy = 'case-study' === $type ? NH_Post_Types::TAX_CASE_TOPIC : NH_Post_Types::TAX_TOPIC;
			$map      = 'case-study' === $type ? $case_map : $hr_map;
			$topics   = array();
			if ( 'faq' === $type ) {
				$taxonomy = '';
			} elseif ( 'article' === $type && isset( $overrides[ $item['slug'] ] ) ) {
				$topics[] = $overrides[ $item['slug'] ];
			} else {
				foreach ( $item['categories'] as $cat ) {
					$topics[] = isset( $map[ $cat ] ) ? $map[ $cat ] : $cat; // Unknown category: keep its slug as a topic.
				}
			}

			$tags = $item['tags'];
			if ( in_array( self::SME_CATEGORY, $item['categories'], true ) ) {
				$tags[] = self::SME_TAG;
			}

			$related = array();
			foreach ( self::decode_json_list( isset( $item['meta']['_betterdocs_related_articles'] ) ? $item['meta']['_betterdocs_related_articles'] : '' ) as $block ) {
				foreach ( isset( $block['selectedDocs'] ) ? (array) $block['selectedDocs'] : array() as $doc ) {
					if ( isset( $doc['value'] ) && is_numeric( $doc['value'] ) && (int) $doc['value'] !== $item['old_id'] ) {
						$related[] = (int) $doc['value'];
					}
				}
			}

			$attachments = array();
			foreach ( self::decode_json_list( isset( $item['meta']['_betterdocs_attachments'] ) ? $item['meta']['_betterdocs_attachments'] : '' ) as $file ) {
				$url = isset( $file['url'] ) ? $file['url'] : ( isset( $file['fileurl'] ) ? $file['fileurl'] : '' );
				$scheme = $url ? wp_parse_url( $url, PHP_URL_SCHEME ) : '';
				if ( in_array( $scheme, array( 'http', 'https' ), true ) ) {
					$title         = ! empty( $file['title'] ) ? $file['title'] : ( ! empty( $file['filename'] ) ? $file['filename'] : basename( $url ) );
					$attachments[] = array(
						'title' => sanitize_text_field( $title ),
						'url'   => esc_url_raw( $url ),
					);
				}
			}

			$rows[] = array(
				'item'        => $item,
				'type'        => $type,
				'taxonomy'    => $taxonomy,
				'topics'      => array_values( array_unique( $topics ) ),
				'tags'        => array_values( array_unique( $tags ) ),
				'views'       => isset( $item['meta']['_betterdocs_meta_views'] ) ? absint( $item['meta']['_betterdocs_meta_views'] ) : 0,
				'related'     => array_values( array_unique( $related ) ),
				'attachments' => $attachments,
				'level'       => 'faq' === $type ? 1 : null, // FAQs from BetterDocs introduce the platform: every logged-in member.
				'exists'      => (bool) get_page_by_path( $item['slug'], OBJECT, NH_Post_Types::CPT ),
			);
		}
		return $rows;
	}

	/* ---------- Import ---------- */

	private static function ensure_term( $slug, $name, $taxonomy, $description = '' ) {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term ) {
			return (int) $term->term_id;
		}
		$created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug, 'description' => $description ) );
		return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
	}

	public static function run( $data ) {
		$plan   = self::plan( $data );
		$report = array( 'created' => array(), 'skipped' => array(), 'failed' => array(), 'related' => 0, 'attachments' => 0, 'new_topics' => array() );

		// Topic term IDs per taxonomy: the agreed lists, plus unknown categories from a newer export.
		$topic_ids = array(
			NH_Post_Types::TAX_TOPIC      => array(),
			NH_Post_Types::TAX_CASE_TOPIC => array(),
		);
		$sets = array(
			NH_Post_Types::TAX_TOPIC      => array( NH_Upgrade::hr_topics(), NH_Upgrade::hr_category_map() ),
			NH_Post_Types::TAX_CASE_TOPIC => array( NH_Upgrade::case_topics(), NH_Upgrade::case_category_map() ),
		);
		foreach ( $sets as $taxonomy => $set ) {
			list( $names, $map ) = $set;
			if ( NH_Post_Types::TAX_CASE_TOPIC === $taxonomy ) {
				$descriptions = array();
				foreach ( $map as $old => $new ) {
					if ( isset( $data['categories'][ $old ] ) ) {
						$descriptions[ $new ] = $data['categories'][ $old ]['description'];
					}
				}
				$topic_ids[ $taxonomy ] = NH_Upgrade::ensure_case_topics( $descriptions );
			}
			foreach ( $names as $slug => $name ) {
				$desc = '';
				$img  = 0;
				foreach ( $map as $old => $new ) {
					if ( $new === $slug && isset( $data['categories'][ $old ] ) ) {
						if ( ! $desc && $old === $slug ) {
							$desc = $data['categories'][ $old ]['description'];
						}
						if ( ! $img && $data['categories'][ $old ]['image'] && wp_attachment_is_image( $data['categories'][ $old ]['image'] ) ) {
							$img = $data['categories'][ $old ]['image'];
						}
					}
				}
				if ( empty( $topic_ids[ $taxonomy ][ $slug ] ) ) {
					$topic_ids[ $taxonomy ][ $slug ] = self::ensure_term( $slug, $name, $taxonomy, $desc );
				}
				$id = $topic_ids[ $taxonomy ][ $slug ];
				if ( $img && $id && ! get_term_meta( $id, 'nh_image_id', true ) ) {
					update_term_meta( $id, 'nh_image_id', $img );
				}
			}
		}
		foreach ( $plan as $row ) {
			foreach ( $row['taxonomy'] ? $row['topics'] : array() as $slug ) {
				if ( empty( $topic_ids[ $row['taxonomy'] ][ $slug ] ) ) {
					$cat = isset( $data['categories'][ $slug ] ) ? $data['categories'][ $slug ] : array( 'name' => $slug, 'description' => '' );
					$topic_ids[ $row['taxonomy'] ][ $slug ] = self::ensure_term( $slug, $cat['name'], $row['taxonomy'], $cat['description'] );
					$report['new_topics'][]                 = $cat['name'];
				}
			}
		}

		// Tags.
		$tag_ids = array();
		foreach ( $data['tags'] as $slug => $name ) {
			$tag_ids[ $slug ] = self::ensure_term( $slug, $name, NH_Post_Types::TAX_TAG );
		}
		$tag_ids[ self::SME_TAG ] = self::ensure_term( self::SME_TAG, 'SME', NH_Post_Types::TAX_TAG );

		$type_ids = array();
		foreach ( array( 'article', 'case-study', 'faq' ) as $slug ) {
			$term              = get_term_by( 'slug', $slug, NH_Post_Types::TAX_TYPE );
			$type_ids[ $slug ] = $term ? (int) $term->term_id : 0;
		}

		// Pass 1: create resources.
		$id_map = array();
		foreach ( $plan as $row ) {
			$item = $row['item'];
			if ( $row['exists'] ) {
				$existing                   = get_page_by_path( $item['slug'], OBJECT, NH_Post_Types::CPT );
				$id_map[ $item['old_id'] ] = $existing ? $existing->ID : 0;
				$report['skipped'][]        = $item['title'];
				continue;
			}

			$args = array(
				'post_type'      => NH_Post_Types::CPT,
				'post_title'     => $item['title'],
				'post_name'      => $item['slug'],
				'post_content'   => $item['content'],
				'post_excerpt'   => $item['excerpt'],
				'post_status'    => $item['status'],
				'menu_order'     => $item['menu_order'],
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_author'    => get_current_user_id(),
			);
			if ( $item['date'] && '0000-00-00 00:00:00' !== $item['date'] ) {
				$args['post_date'] = $item['date'];
			}
			if ( $item['date_gmt'] && '0000-00-00 00:00:00' !== $item['date_gmt'] ) {
				$args['post_date_gmt'] = $item['date_gmt'];
			}

			$new_id = wp_insert_post( wp_slash( $args ), true );
			if ( is_wp_error( $new_id ) ) {
				$report['failed'][] = $item['title'] . ': ' . $new_id->get_error_message();
				continue;
			}
			$id_map[ $item['old_id'] ] = $new_id;

			if ( ! empty( $type_ids[ $row['type'] ] ) ) {
				wp_set_object_terms( $new_id, array( $type_ids[ $row['type'] ] ), NH_Post_Types::TAX_TYPE );
			}
			if ( $row['taxonomy'] ) {
				$t_ids = array();
				foreach ( $row['topics'] as $slug ) {
					if ( ! empty( $topic_ids[ $row['taxonomy'] ][ $slug ] ) ) {
						$t_ids[] = $topic_ids[ $row['taxonomy'] ][ $slug ];
					}
				}
				wp_set_object_terms( $new_id, $t_ids, $row['taxonomy'] );
			}
			$g_ids = array();
			foreach ( $row['tags'] as $slug ) {
				if ( ! empty( $tag_ids[ $slug ] ) ) {
					$g_ids[] = $tag_ids[ $slug ];
				}
			}
			wp_set_object_terms( $new_id, $g_ids, NH_Post_Types::TAX_TAG );

			if ( $row['views'] ) {
				update_post_meta( $new_id, NH_Stats::VIEWS_META, $row['views'] );
			}
			if ( null !== $row['level'] ) {
				update_post_meta( $new_id, NH_Access::META, $row['level'] );
			}
			if ( $row['attachments'] ) {
				update_post_meta( $new_id, NH_Related::ATTACH_META, $row['attachments'] );
				$report['attachments'] += count( $row['attachments'] );
			}
			update_post_meta( $new_id, '_nh_legacy_id', $item['old_id'] );

			$report['created'][ $row['type'] ] = ( isset( $report['created'][ $row['type'] ] ) ? $report['created'][ $row['type'] ] : 0 ) + 1;
		}

		// Pass 2: related articles, now that every old ID has a new one.
		foreach ( $plan as $row ) {
			$new_id = isset( $id_map[ $row['item']['old_id'] ] ) ? $id_map[ $row['item']['old_id'] ] : 0;
			if ( ! $new_id || $row['exists'] || ! $row['related'] ) {
				continue;
			}
			$related = array();
			foreach ( $row['related'] as $old ) {
				if ( ! empty( $id_map[ $old ] ) && $id_map[ $old ] !== $new_id ) {
					$related[] = $id_map[ $old ];
				}
			}
			if ( $related ) {
				update_post_meta( $new_id, NH_Related::META, array_slice( array_values( array_unique( $related ) ), 0, NH_Related::MAX ) );
				$report['related']++;
			}
		}

		// Old BetterDocs category URLs of categories outside the plan redirect to the topic with the same slug.
		$legacy = array();
		foreach ( array_keys( $data['categories'] ) as $old ) {
			if ( ! isset( $sets[ NH_Post_Types::TAX_TOPIC ][1][ $old ] ) && ! isset( $sets[ NH_Post_Types::TAX_CASE_TOPIC ][1][ $old ] ) ) {
				$legacy[ $old ] = $old;
			}
		}
		update_option( NH_Rewrite::OPTION_LEGACY, $legacy, false );

		NH_Rewrite::schedule_rebuild();
		NH_Frontend::flush_topic_cache();

		return $report;
	}

	/* ---------- Request handling ---------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'neurohr-hub' ) );
		}
		check_admin_referer( self::ACTION );

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';

		if ( 'cancel' === $step ) {
			delete_transient( self::transient_key() );
			wp_safe_redirect( self::page_url() );
			exit;
		}

		if ( 'upload' === $step ) {
			$file = isset( $_FILES['nh_wxr'] ) ? $_FILES['nh_wxr'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below.
			if ( ! $file || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				wp_safe_redirect( self::page_url( array( 'nh_error' => 'upload' ) ) );
				exit;
			}
			if ( $file['size'] > self::MAX_BYTES || ! preg_match( '/\.xml$/i', $file['name'] ) ) {
				wp_safe_redirect( self::page_url( array( 'nh_error' => 'type' ) ) );
				exit;
			}
			$xml  = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$data = self::parse( (string) $xml );
			if ( is_wp_error( $data ) || empty( $data['items'] ) ) {
				wp_safe_redirect( self::page_url( array( 'nh_error' => 'parse' ) ) );
				exit;
			}
			set_transient( self::transient_key(), $data, HOUR_IN_SECONDS );
			wp_safe_redirect( self::page_url( array( 'step' => 'preview' ) ) );
			exit;
		}

		if ( 'attachments' === $step ) {
			update_option( self::REPORT . '_files', NH_Media::convert_attachments(), false );
			wp_safe_redirect( self::page_url( array( 'step' => 'files' ) ) );
			exit;
		}

		if ( 'import' === $step ) {
			$data = get_transient( self::transient_key() );
			if ( ! is_array( $data ) ) {
				wp_safe_redirect( self::page_url( array( 'nh_error' => 'expired' ) ) );
				exit;
			}
			$report = self::run( $data );
			delete_transient( self::transient_key() );
			update_option( self::REPORT, $report, false );
			wp_safe_redirect( self::page_url( array( 'step' => 'done' ) ) );
			exit;
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/* ---------- Screens ---------- */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display state only.
		$step  = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		$error = isset( $_GET['nh_error'] ) ? sanitize_key( wp_unslash( $_GET['nh_error'] ) ) : '';
		// phpcs:enable
		$errors = array(
			'upload'  => __( 'The upload failed. Please choose the file again.', 'neurohr-hub' ),
			'type'    => __( 'Please upload the .xml export file (max 30 MB).', 'neurohr-hub' ),
			'parse'   => __( 'No BetterDocs docs or FAQs were found in this file. Export them from the BetterDocs site with Tools → Export.', 'neurohr-hub' ),
			'expired' => __( 'The preview expired. Please upload the file again.', 'neurohr-hub' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import from BetterDocs', 'neurohr-hub' ); ?></h1>

			<?php if ( isset( $errors[ $error ] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $errors[ $error ] ); ?></p></div>
			<?php endif; ?>

			<?php
			if ( 'files' === $step ) {
				self::render_files_report();
			} elseif ( 'done' === $step ) {
				self::render_report();
			} elseif ( 'preview' === $step && is_array( get_transient( self::transient_key() ) ) ) {
				self::render_preview( get_transient( self::transient_key() ) );
			} else {
				self::render_upload();
			}
			?>
		</div>
		<?php
	}

	/** Box under the upload form: move BetterDocs attachments into protected downloads. */
	private static function render_attachments_box() {
		$count = count(
			get_posts(
				array(
					'post_type'      => NH_Post_Types::CPT,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => NH_Related::ATTACH_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- admin tool.
				)
			)
		);
		if ( ! $count ) {
			return;
		}
		?>
		<hr style="margin:24px 0">
		<h2><?php esc_html_e( 'BetterDocs attachments', 'neurohr-hub' ); ?></h2>
		<p><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d resource still has BetterDocs attachments.', '%d resources still have BetterDocs attachments.', $count, 'neurohr-hub' ), $count ) ); ?>
			<?php esc_html_e( 'Files stored on this site become protected Template downloads (readable by the same members as the resource) and are linked under it. Links to other sites stay as they are.', 'neurohr-hub' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<button type="submit" name="step" value="attachments" class="button"><?php esc_html_e( 'Move attachments into protected downloads', 'neurohr-hub' ); ?></button>
		</form>
		<?php
	}

	private static function render_files_report() {
		$r = get_option( self::REPORT . '_files', array() );
		?>
		<div class="notice notice-success inline"><p>
			<?php echo esc_html( sprintf( /* translators: 1: moved, 2: kept */ __( 'Moved into protected downloads: %1$d. Links to other sites kept: %2$d.', 'neurohr-hub' ), count( isset( $r['moved'] ) ? $r['moved'] : array() ), isset( $r['kept'] ) ? (int) $r['kept'] : 0 ) ); ?>
		</p></div>
		<?php if ( ! empty( $r['moved'] ) ) : ?>
			<p><strong><?php esc_html_e( 'The original public copies are still in the media library. Check the new downloads, then delete these files from Media:', 'neurohr-hub' ); ?></strong></p>
			<ul style="list-style:disc;padding-left:20px">
				<?php foreach ( $r['moved'] as $m ) : ?>
					<li><?php echo esc_html( $m['title'] ); ?> — <code><?php echo esc_html( $m['url'] ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( ! empty( $r['missing'] ) ) : ?>
			<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Not moved (file not found on this site — upload it to a Template resource by hand):', 'neurohr-hub' ); ?></strong> <?php echo esc_html( implode( '; ', $r['missing'] ) ); ?></p></div>
		<?php endif; ?>
		<?php
	}

	private static function render_upload() {
		?>
		<p><?php esc_html_e( 'On the site that runs BetterDocs, go to Tools → Export, choose "Docs" (and "FAQ" if listed), download the file, then upload it here. You will see a preview before anything is imported.', 'neurohr-hub' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="step" value="upload">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="file" name="nh_wxr" accept=".xml" required>
			<?php submit_button( __( 'Upload and preview', 'neurohr-hub' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
		self::render_attachments_box();
	}

	private static function render_preview( $data ) {
		$plan   = self::plan( $data );
		$topics = self::topic_names();
		$counts = array_count_values( array_column( $plan, 'type' ) );
		$skip   = count( array_filter( array_column( $plan, 'exists' ) ) );
		?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: total, 2: articles, 3: case studies, 4: FAQs, 5: skipped */
					__( '%1$d items found: %2$d Articles, %3$d Case Studies, %4$d FAQs. %5$d already exist and will be skipped.', 'neurohr-hub' ),
					count( $plan ),
					isset( $counts['article'] ) ? $counts['article'] : 0,
					isset( $counts['case-study'] ) ? $counts['case-study'] : 0,
					isset( $counts['faq'] ) ? $counts['faq'] : 0,
					$skip
				)
			);
			?>
		</p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Title', 'neurohr-hub' ); ?></th>
				<th><?php esc_html_e( 'Type', 'neurohr-hub' ); ?></th>
				<th><?php esc_html_e( 'Topic', 'neurohr-hub' ); ?></th>
				<th><?php esc_html_e( 'Tags', 'neurohr-hub' ); ?></th>
				<th><?php esc_html_e( 'Access', 'neurohr-hub' ); ?></th>
				<th><?php esc_html_e( 'Status', 'neurohr-hub' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $plan as $row ) : ?>
				<?php
				$topic_names = array();
				foreach ( $row['topics'] as $slug ) {
					$topic_names[] = isset( $topics[ $slug ] ) ? $topics[ $slug ] : $slug . ' ' . __( '(new)', 'neurohr-hub' );
				}
				$type_levels = NH_Access::get_type_levels();
				$level       = null !== $row['level'] ? $row['level'] : ( isset( $type_levels[ $row['type'] ] ) ? $type_levels[ $row['type'] ] : NH_Access::default_level() );
				?>
				<tr>
					<td><?php echo esc_html( $row['item']['title'] ); ?></td>
					<td><?php echo esc_html( $row['type'] ); ?></td>
					<td><?php echo esc_html( implode( ', ', $topic_names ) ); ?></td>
					<td><?php echo esc_html( implode( ', ', $row['tags'] ) ); ?></td>
					<td><?php echo esc_html( nh_hub_level_label( $level ) ); ?></td>
					<td><?php echo $row['exists'] ? esc_html__( 'Skip (exists)', 'neurohr-hub' ) : esc_html__( 'New', 'neurohr-hub' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;display:flex;gap:8px">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<button type="submit" name="step" value="import" class="button button-primary"><?php esc_html_e( 'Run import', 'neurohr-hub' ); ?></button>
			<button type="submit" name="step" value="cancel" class="button"><?php esc_html_e( 'Cancel', 'neurohr-hub' ); ?></button>
		</form>
		<?php
	}

	private static function render_report() {
		$r       = get_option( self::REPORT, array() );
		$created = isset( $r['created'] ) ? (array) $r['created'] : array();
		?>
		<div class="notice notice-success inline">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: articles, 2: case studies, 3: FAQs, 4: related links, 5: attachments */
						__( 'Imported %1$d Articles, %2$d Case Studies, %3$d FAQs. Related links: %4$d. Attachments: %5$d.', 'neurohr-hub' ),
						isset( $created['article'] ) ? $created['article'] : 0,
						isset( $created['case-study'] ) ? $created['case-study'] : 0,
						isset( $created['faq'] ) ? $created['faq'] : 0,
						isset( $r['related'] ) ? $r['related'] : 0,
						isset( $r['attachments'] ) ? $r['attachments'] : 0
					)
				);
				?>
			</p>
		</div>
		<?php if ( ! empty( $r['skipped'] ) ) : ?>
			<p><strong><?php esc_html_e( 'Skipped (already existed):', 'neurohr-hub' ); ?></strong> <?php echo esc_html( implode( ', ', $r['skipped'] ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $r['failed'] ) ) : ?>
			<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Failed:', 'neurohr-hub' ); ?></strong> <?php echo esc_html( implode( '; ', $r['failed'] ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! empty( $r['new_topics'] ) ) : ?>
			<p><strong><?php esc_html_e( 'Categories not in the topic plan (created as new topics):', 'neurohr-hub' ); ?></strong> <?php echo esc_html( implode( ', ', $r['new_topics'] ) ); ?></p>
		<?php endif; ?>
		<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . NH_Post_Types::CPT . '&page=nh-hub-dashboard' ) ); ?>"><?php esc_html_e( 'Open the dashboard', 'neurohr-hub' ); ?></a></p>
		<?php
	}
}
