<?php
/**
 * "Last reviewed" date.
 *
 * Laws and practice change, so instead of adding a new article each time, the existing
 * one is updated and marked as reviewed. Members see "Last reviewed {date}" on the page
 * and an "Updated" badge in lists for a while; the Dashboard lists content that has not
 * been reviewed for too long.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Review {

	const META   = '_nh_reviewed';
	const NONCE  = 'nh_hub_review_nonce';
	const BULK   = 'nh_mark_reviewed';
	const COLUMN = 'nh_reviewed';

	public static function init() {
		add_action( 'add_meta_boxes_' . NH_Post_Types::CPT, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . NH_Post_Types::CPT . '_posts_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_' . NH_Post_Types::CPT . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . NH_Post_Types::CPT . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'sort_query' ) );
		add_filter( 'bulk_actions-edit-' . NH_Post_Types::CPT, array( __CLASS__, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-' . NH_Post_Types::CPT, array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
	}

	/* ---------- Dates ---------- */

	/** Review date (Y-m-d) or '' when never marked. */
	public static function reviewed( $post ) {
		$post  = get_post( $post );
		$value = $post ? (string) get_post_meta( $post->ID, self::META, true ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/** Timestamp the content was last known to be correct: review date, else publish date. */
	public static function checked_time( $post ) {
		$post     = get_post( $post );
		$reviewed = self::reviewed( $post );
		return $reviewed ? strtotime( $reviewed . ' 12:00:00' ) : (int) get_post_time( 'U', true, $post );
	}

	public static function months() {
		return max( 1, (int) NH_Display::get( 'review_months' ) );
	}

	/** Not reviewed (or published) within the review period. */
	public static function is_due( $post ) {
		return self::checked_time( $post ) < strtotime( '-' . self::months() . ' months' );
	}

	/** Reviewed recently, and well after it was first published: show "Updated". */
	public static function is_recently_updated( $post ) {
		$post     = get_post( $post );
		$reviewed = self::reviewed( $post );
		if ( ! $post || ! $reviewed ) {
			return false;
		}
		$time = strtotime( $reviewed . ' 12:00:00' );
		$days = max( 0, (int) NH_Display::get( 'updated_days' ) );
		return $days && $time >= strtotime( '-' . $days . ' days' )
			&& $time > (int) get_post_time( 'U', true, $post ) + 7 * DAY_IN_SECONDS;
	}

	/** "Last reviewed 3 March 2026" or "Published 3 March 2026". */
	public static function label( $post ) {
		$reviewed = self::reviewed( $post );
		$date     = date_i18n( get_option( 'date_format' ), self::checked_time( $post ) );
		/* translators: %s: date */
		return $reviewed ? sprintf( __( 'Last reviewed %s', 'neurohr-hub' ), $date ) : sprintf( __( 'Published %s', 'neurohr-hub' ), $date );
	}

	public static function badge( $post ) {
		return self::is_recently_updated( $post ) ? '<span class="nh-updated">' . esc_html__( 'Updated', 'neurohr-hub' ) . '</span>' : '';
	}

	/* ---------- Editor box ---------- */

	public static function add_meta_box() {
		add_meta_box( 'nh_review', __( 'Content review', 'neurohr-hub' ), array( __CLASS__, 'render_meta_box' ), NH_Post_Types::CPT, 'side', 'default' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'nh_hub_review', self::NONCE );
		$reviewed = self::reviewed( $post );
		?>
		<p><label><input type="checkbox" name="nh_reviewed_today" value="1">
			<strong><?php esc_html_e( 'Reviewed today', 'neurohr-hub' ); ?></strong></label><br>
			<span class="description"><?php esc_html_e( 'Tick after checking the content is still correct (e.g. after a law change). Members see the date.', 'neurohr-hub' ); ?></span></p>
		<p><label for="nh-reviewed"><?php esc_html_e( 'Last reviewed', 'neurohr-hub' ); ?></label><br>
			<input type="date" id="nh-reviewed" name="nh_reviewed" value="<?php echo esc_attr( $reviewed ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></p>
		<?php if ( $reviewed || 'publish' === $post->post_status ) : ?>
			<p class="description">
				<?php
				echo self::is_due( $post )
					/* translators: %d: months */
					? '<span style="color:#b32d2e">' . esc_html( sprintf( __( 'Due for review: not checked for %d months or more.', 'neurohr-hub' ), self::months() ) ) . '</span>'
					: esc_html( self::label( $post ) );
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'nh_hub_review' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['nh_reviewed_today'] ) ) {
			update_post_meta( $post_id, self::META, current_time( 'Y-m-d' ) );
			return;
		}
		$value = isset( $_POST['nh_reviewed'] ) ? sanitize_text_field( wp_unslash( $_POST['nh_reviewed'] ) ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && strtotime( $value ) ) {
			update_post_meta( $post_id, self::META, min( $value, current_time( 'Y-m-d' ) ) );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/* ---------- List column, sorting and bulk action ---------- */

	public static function add_column( $columns ) {
		$columns[ self::COLUMN ] = __( 'Reviewed', 'neurohr-hub' );
		return $columns;
	}

	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$reviewed = self::reviewed( $post_id );
		$text     = $reviewed ? date_i18n( 'j M Y', strtotime( $reviewed ) ) : '—';
		if ( 'publish' === get_post_status( $post_id ) && self::is_due( $post_id ) ) {
			echo '<span class="nh-due">' . esc_html( $text ) . '<br><small>' . esc_html__( 'due', 'neurohr-hub' ) . '</small></span>';
		} else {
			echo esc_html( $text );
		}
	}

	public static function sortable( $columns ) {
		$columns[ self::COLUMN ] = self::COLUMN;
		return $columns;
	}

	public static function sort_query( $query ) {
		if ( is_admin() && $query->is_main_query() && self::COLUMN === $query->get( 'orderby' ) ) {
			$query->set( 'meta_query', array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				'nh_rev'   => array( 'key' => self::META, 'compare' => 'EXISTS' ),
				'nh_none'  => array( 'key' => self::META, 'compare' => 'NOT EXISTS' ),
			) );
			$query->set( 'orderby', array( 'nh_rev' => $query->get( 'order' ) ? $query->get( 'order' ) : 'ASC', 'date' => 'ASC' ) );
		}
	}

	public static function bulk_action( $actions ) {
		$actions[ self::BULK ] = __( 'Mark as reviewed today', 'neurohr-hub' );
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( self::BULK !== $action ) {
			return $redirect;
		}
		$done  = 0;
		$today = current_time( 'Y-m-d' );
		foreach ( (array) $ids as $id ) {
			if ( current_user_can( 'edit_post', $id ) ) {
				update_post_meta( (int) $id, self::META, $today );
				$done++;
			}
		}
		return add_query_arg( 'nh_reviewed_done', $done, $redirect );
	}

	public static function bulk_notice() {
		if ( isset( $_GET['nh_reviewed_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$n = absint( $_GET['nh_reviewed_done'] ); // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: %d: number of resources */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d resource marked as reviewed today.', '%d resources marked as reviewed today.', $n, 'neurohr-hub' ), $n ) ) . '</p></div>';
		}
	}
}
