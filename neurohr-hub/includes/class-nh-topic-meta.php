<?php
/**
 * Topic icon (media library) and display order, on the Topics admin screens.
 * The icon and order are used by the topic cards on the hub home.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_Topic_Meta {

	/** HR topics and case topics both get an icon and an order. */
	public static function taxonomies() {
		return array( NH_Post_Types::TAX_TOPIC, NH_Post_Types::TAX_CASE_TOPIC );
	}

	public static function init() {
		foreach ( self::taxonomies() as $tax ) {
			add_action( $tax . '_add_form_fields', array( __CLASS__, 'add_fields' ) );
			add_action( $tax . '_edit_form_fields', array( __CLASS__, 'edit_fields' ) );
			add_action( 'created_' . $tax, array( __CLASS__, 'save' ) );
			add_action( 'edited_' . $tax, array( __CLASS__, 'save' ) );
			add_filter( 'manage_edit-' . $tax . '_columns', array( __CLASS__, 'columns' ) );
			add_filter( 'manage_' . $tax . '_custom_column', array( __CLASS__, 'column' ), 10, 3 );
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->taxonomy, self::taxonomies(), true ) && in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			wp_enqueue_media();
		}
	}

	public static function add_fields() {
		wp_nonce_field( 'nh_topic_meta', 'nh_topic_meta_nonce' );
		?>
		<div class="form-field">
			<label><?php esc_html_e( 'Icon', 'neurohr-hub' ); ?></label>
			<?php NH_Admin::image_field( 'nh_image_id', 0, 'nh-topic-icon' ); ?>
			<p><?php esc_html_e( 'Shown on the topic card on the hub home. A square PNG or SVG-like image works best.', 'neurohr-hub' ); ?></p>
		</div>
		<div class="form-field">
			<label for="nh-order"><?php esc_html_e( 'Order', 'neurohr-hub' ); ?></label>
			<input id="nh-order" type="number" name="nh_order" value="0" style="width:90px">
			<p><?php esc_html_e( 'Lower numbers come first on the hub home. Same number: alphabetical.', 'neurohr-hub' ); ?></p>
		</div>
		<?php
	}

	public static function edit_fields( $term ) {
		wp_nonce_field( 'nh_topic_meta', 'nh_topic_meta_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Icon', 'neurohr-hub' ); ?></th>
			<td><?php NH_Admin::image_field( 'nh_image_id', get_term_meta( $term->term_id, 'nh_image_id', true ), 'nh-topic-icon' ); ?>
				<p class="description"><?php esc_html_e( 'Shown on the topic card on the hub home.', 'neurohr-hub' ); ?></p></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="nh-order"><?php esc_html_e( 'Order', 'neurohr-hub' ); ?></label></th>
			<td><input id="nh-order" type="number" name="nh_order" value="<?php echo (int) get_term_meta( $term->term_id, 'nh_order', true ); ?>" style="width:90px">
				<p class="description"><?php esc_html_e( 'Lower numbers come first on the hub home. Same number: alphabetical.', 'neurohr-hub' ); ?></p></td>
		</tr>
		<?php
	}

	public static function save( $term_id ) {
		if ( ! isset( $_POST['nh_topic_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nh_topic_meta_nonce'] ) ), 'nh_topic_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$image = isset( $_POST['nh_image_id'] ) ? absint( $_POST['nh_image_id'] ) : 0;
		if ( $image && wp_attachment_is_image( $image ) ) {
			update_term_meta( $term_id, 'nh_image_id', $image );
		} else {
			delete_term_meta( $term_id, 'nh_image_id' );
		}
		update_term_meta( $term_id, 'nh_order', isset( $_POST['nh_order'] ) ? intval( $_POST['nh_order'] ) : 0 );
		NH_Frontend::flush_topic_cache();
	}

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'name' === $key ) {
				$new['nh_icon'] = __( 'Icon', 'neurohr-hub' );
			}
			$new[ $key ] = $label;
		}
		$new['nh_order'] = __( 'Order', 'neurohr-hub' );
		return $new;
	}

	public static function column( $content, $column, $term_id ) {
		if ( 'nh_icon' === $column ) {
			$image = (int) get_term_meta( $term_id, 'nh_image_id', true );
			return $image ? wp_get_attachment_image( $image, array( 32, 32 ) ) : '—';
		}
		if ( 'nh_order' === $column ) {
			return (string) (int) get_term_meta( $term_id, 'nh_order', true );
		}
		return $content;
	}
}
