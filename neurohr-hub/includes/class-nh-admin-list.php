<?php
/**
 * Knowledge Hub → All Resources: one tab per section (Knowledge Hub, Legal Case Insights,
 * FAQ, Templates, Videos), a topic filter for that section, only the columns that matter
 * for it, and "Add new" buttons that preset the Resource Type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Admin_List {

	const PARAM    = 'nh_section';
	const NEW_TYPE = 'nh_new_type';

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'tabs' ), 1 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'topic_filter' ), 10, 2 );
		add_filter( 'manage_' . NH_Post_Types::CPT . '_posts_columns', array( __CLASS__, 'columns' ), 50 );
		add_action( 'save_post_' . NH_Post_Types::CPT, array( __CLASS__, 'preset_type' ), 5, 3 );
	}

	private static function is_list_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'edit-' . NH_Post_Types::CPT === $screen->id;
	}

	/** Section chosen in the tabs: a section key, 'none' (no type) or ''. */
	public static function current() {
		$key = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return ( 'none' === $key || NH_Sections::get( $key ) && 'all' !== $key ) ? $key : '';
	}

	private static function count( $key ) {
		$args = array(
			'post_type'      => NH_Post_Types::CPT,
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		);
		if ( 'none' === $key ) {
			$args['tax_query'] = array( array( 'taxonomy' => NH_Post_Types::TAX_TYPE, 'operator' => 'NOT EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( $key ) {
			$args['tax_query'] = array( NH_Sections::type_clause( $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$q = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	public static function tabs() {
		if ( ! self::is_list_screen() ) {
			return;
		}
		$current = self::current();
		$base    = admin_url( 'edit.php?post_type=' . NH_Post_Types::CPT );
		$items   = array( '' => __( 'All', 'neurohr-hub' ) );
		foreach ( NH_Sections::all() as $key => $section ) {
			$items[ $key ] = $section['label'];
		}
		$untyped = self::count( 'none' );
		if ( $untyped ) {
			$items['none'] = __( 'No type', 'neurohr-hub' );
		}
		?>
		<div class="nh-list-tabs">
			<nav class="nh-admin-tabs">
				<?php foreach ( $items as $key => $label ) : ?>
					<a href="<?php echo esc_url( $key ? add_query_arg( self::PARAM, $key, $base ) : $base ); ?>" class="<?php echo $current === $key ? 'is-active' : ''; ?><?php echo 'none' === $key ? ' is-warning' : ''; ?>">
						<?php echo esc_html( $label ); ?> <span class="count"><?php echo (int) self::count( $key ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php if ( $current && 'none' !== $current ) : ?>
				<div class="nh-list-tabs__actions">
				<?php $section = NH_Sections::get( $current ); ?>
				<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'post_type' => NH_Post_Types::CPT, self::NEW_TYPE => $section['types'][0] ), admin_url( 'post-new.php' ) ) ); ?>">
					<?php echo esc_html( sprintf( /* translators: %s: section */ __( 'Add to %s', 'neurohr-hub' ), $section['label'] ) ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( NH_Rewrite::url( $current ) ); ?>" target="_blank"><?php esc_html_e( 'View section', 'neurohr-hub' ); ?> ↗</a>
				</div>
			<?php endif; ?>
		</div>
		<script>
		/* Show the tabs under the page title, where WordPress puts its own list views. */
		document.addEventListener( 'DOMContentLoaded', function () {
			var tabs = document.querySelector( '.nh-list-tabs' ), end = document.querySelector( '.wp-header-end' );
			if ( tabs && end ) { end.parentNode.insertBefore( tabs, end.nextSibling ); }
		} );
		</script>
		<?php
	}

	public static function filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || NH_Post_Types::CPT !== $query->get( 'post_type' ) ) {
			return;
		}
		$query->set( NH_Sections::QUERY_VAR, '' ); // The front-end section var means nothing here.
		$key = self::current();
		if ( ! $key ) {
			return;
		}
		$tax_query   = (array) $query->get( 'tax_query' );
		$tax_query[] = 'none' === $key
			? array( 'taxonomy' => NH_Post_Types::TAX_TYPE, 'operator' => 'NOT EXISTS' )
			: NH_Sections::type_clause( $key );
		$query->set( 'tax_query', $tax_query );
	}

	/** Topic dropdown for the chosen section (HR Topics or Case Topics). */
	public static function topic_filter( $post_type, $which = 'top' ) {
		if ( NH_Post_Types::CPT !== $post_type || 'top' !== $which ) {
			return;
		}
		$key = self::current();
		if ( $key && 'none' !== $key ) {
			echo '<input type="hidden" name="' . esc_attr( self::PARAM ) . '" value="' . esc_attr( $key ) . '">';
		}
		$section = ( $key && 'none' !== $key ) ? NH_Sections::get( $key ) : null;
		if ( ! $section || ! $section['taxonomy'] ) {
			return;
		}
		$var      = NH_Sections::topic_var( $section['taxonomy'] );
		$selected = isset( $_GET[ $var ] ) ? sanitize_title( wp_unslash( $_GET[ $var ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		wp_dropdown_categories(
			array(
				'taxonomy'        => $section['taxonomy'],
				'name'            => $var,
				'value_field'     => 'slug',
				'selected'        => $selected,
				'show_option_all' => __( 'All topics', 'neurohr-hub' ),
				'hide_empty'      => false,
				'hierarchical'    => true,
				'orderby'         => 'name',
			)
		);
	}

	/** Hide columns that mean nothing in the chosen section. */
	public static function columns( $columns ) {
		$key = self::current();
		if ( ! $key || 'none' === $key ) {
			return $columns;
		}
		$section = NH_Sections::get( $key );
		unset( $columns[ 'taxonomy-' . NH_Post_Types::TAX_TYPE ] );
		if ( NH_Post_Types::TAX_CASE_TOPIC !== $section['taxonomy'] ) {
			unset( $columns[ 'taxonomy-' . NH_Post_Types::TAX_CASE_TOPIC ] );
		}
		if ( NH_Post_Types::TAX_TOPIC !== $section['taxonomy'] ) {
			unset( $columns[ 'taxonomy-' . NH_Post_Types::TAX_TOPIC ] );
		}
		if ( 'templates' !== $key ) {
			unset( $columns['nh_downloads'] );
		}
		return $columns;
	}

	/** post-new.php?…&nh_new_type=video: the new resource starts with that type ticked. */
	public static function preset_type( $post_id, $post, $update ) {
		if ( $update || 'auto-draft' !== $post->post_status || ! isset( $_GET[ self::NEW_TYPE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$slug = sanitize_title( wp_unslash( $_GET[ self::NEW_TYPE ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$term = get_term_by( 'slug', $slug, NH_Post_Types::TAX_TYPE );
		if ( $term && current_user_can( 'edit_post', $post_id ) ) {
			wp_set_object_terms( $post_id, array( (int) $term->term_id ), NH_Post_Types::TAX_TYPE );
		}
	}
}
