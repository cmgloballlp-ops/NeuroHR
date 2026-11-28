<?php
/**
 * Knowledge Hub → News Categories: tidy the news categories once (0.16).
 * Shows every post with a suggested main category, tags and "newer update"; nothing changes
 * until the owner checks the table and presses Apply. Post addresses, text and dates are
 * never touched. Removed categories keep their address working (301 to the news home).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NH_News_Tidy {

	const PAGE = 'nh-news-tidy';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_nh_news_tidy', array( __CLASS__, 'apply' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . NH_Post_Types::CPT, __( 'News Categories', 'neurohr-hub' ), __( 'News Categories', 'neurohr-hub' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . NH_Post_Types::CPT . '&page=' . self::PAGE ) );
	}

	/** The suggested categories: slug => name, description. */
	public static function targets() {
		return array(
			'employment-laws'         => array( 'Employment Law', 'Changes to the Employment Act, labour ordinances and other laws that shape how you hire, manage and part ways with employees.' ),
			'payroll-statutory'       => array( 'Payroll & Statutory', 'EPF, SOCSO, EIS, minimum wage, tax and stamping: what changes in payroll, and from when.' ),
			'public-holidays'         => array( 'Public Holidays', 'National and state public holidays, additional holidays and replacement days.' ),
			'workplace-safety-health' => array( 'Workplace Safety & Health', 'OSHA, DOSH and psychosocial risks: keeping your workplace safe and compliant.' ),
			'budget-policy'           => array( 'Budget & Policy', 'National budgets, government policies and incentives that affect employers.' ),
			'hr-trends'               => array( 'HR Trends', 'Ideas, data and trends shaping HR in Malaysia.' ),
		);
	}

	/** Main category from the title (and the post's current categories). */
	public static function suggest_category( $title, $current_slugs = array() ) {
		$t     = strtolower( $title );
		$rules = array(
			'public-holidays'         => '/holiday|cuti umum/',
			'budget-policy'           => '/\bbudget\b|belanjawan/',
			'workplace-safety-health' => '/osha|occupational safety|safety and health|psychosocial|dosh/',
			'employment-laws'         => '/apprentice/',
			'payroll-statutory'       => '/\bepf\b|kwsp|perkeso|socso|social security|lindung|\beis\b|employment insurance|minimum wage|stamping|stamp duty|\bpcb\b|lhdn|\btax\b|hrd ?corp|\blevy\b|payroll|e-invoice/',
			'hr-trends'               => '/\btrend|\bbonus\b|future of work|survey|outlook/',
		);
		foreach ( $rules as $slug => $re ) {
			if ( preg_match( $re, $t ) ) {
				return $slug;
			}
		}
		if ( in_array( 'hr-trends', $current_slugs, true ) && ! in_array( 'employment-laws', $current_slugs, true ) && ! preg_match( '/\bact\b|law|discriminat|bully/', $t ) ) {
			return 'hr-trends';
		}
		return 'employment-laws';
	}

	/** Tags from the title. */
	public static function suggest_tags( $title ) {
		$map = array(
			'EPF'                => '/\bEPF\b|KWSP/i',
			'PERKESO'            => '/PERKESO|SOCSO|Social Security|LINDUNG/i',
			'EIS'                => '/\bEIS\b|Employment Insurance/i',
			'Minimum Wage'       => '/minimum wage/i',
			'Public Holiday'     => '/holiday/i',
			'Foreign Workers'    => '/foreign|non-malaysian/i',
			'PDPA'               => '/PDPA|personal data/i',
			'OSHA'               => '/OSHA|Occupational Safety/i',
			'Gig Workers'        => '/\bgig\b/i',
			'Workplace Bullying' => '/bully/i',
			'Discrimination'     => '/discriminat/i',
			'Flexible Work'      => '/flexible work|\bFWA\b/i',
			'Stamp Duty'         => '/stamping|stamp duty/i',
			'Medical Certificate' => '/\bMC\b|medical cert/i',
			'Sabah & Sarawak'    => '/sabah|sarawak/i',
			'Employment Act'     => '/employment act/i',
			'Apprenticeship'     => '/apprentice/i',
			'Bonus'              => '/\bbonus\b/i',
		);
		$out = array();
		foreach ( $map as $tag => $re ) {
			if ( preg_match( $re, $title ) ) {
				$out[] = $tag;
			}
		}
		if ( preg_match( '/budget (\d{4})/i', $title, $m ) ) {
			$out[] = 'Budget ' . $m[1];
		}
		return $out;
	}

	/** Posts about the same thing: the newest is the "newer update" of the others. */
	public static function suggest_newer( array $posts ) {
		$groups = array(
			'bully'       => function ( $t ) { return (bool) preg_match( '/bully/i', $t ); },
			'epf-foreign' => function ( $t ) { return preg_match( '/\bEPF\b|KWSP/i', $t ) && preg_match( '/foreign|non-malaysian/i', $t ); },
		);
		$out = array();
		foreach ( $groups as $test ) {
			$in = array_values( array_filter( $posts, function ( $p ) use ( $test ) { return $test( $p->post_title ); } ) );
			if ( count( $in ) < 2 ) {
				continue;
			}
			usort( $in, function ( $a, $b ) { return strcmp( $b->post_date, $a->post_date ); } );
			foreach ( array_slice( $in, 1 ) as $old ) {
				$out[ $old->ID ] = $in[0]->ID;
			}
		}
		return $out;
	}

	public static function posts() {
		return get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'future', 'draft' ), 'numberposts' => 300, 'orderby' => 'date', 'order' => 'DESC' ) );
	}

	/** Categories that are not in the suggested set (could be removed). */
	public static function others() {
		$keep = array_keys( self::targets() );
		$out  = array();
		foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) {
			if ( ! in_array( $c->slug, $keep, true ) && (int) $c->term_id !== (int) get_option( 'default_category' ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$posts = self::posts();
		$newer = self::suggest_newer( $posts );
		$all   = $posts;
		echo '<div class="wrap nh-admin"><h1>' . esc_html__( 'News Categories', 'neurohr-hub' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- message only.
		if ( isset( $_GET['done'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( /* translators: 1: posts, 2: categories */ __( 'Done: %1$d posts updated, %2$d categories removed.', 'neurohr-hub' ), absint( $_GET['done'] ), isset( $_GET['removed'] ) ? absint( $_GET['removed'] ) : 0 ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		echo '<p>' . esc_html__( 'Check the suggestions, change anything you like, then press Apply. Nothing changes before that. Post addresses, text and dates are never touched; you can run this again any time.', 'neurohr-hub' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nh_news_tidy">';
		wp_nonce_field( 'nh_news_tidy' );

		echo '<h2>' . esc_html__( '1. Categories', 'neurohr-hub' ) . '</h2><p class="description">' . esc_html__( 'Name and one line about each (shown on the news home). Missing ones are created.', 'neurohr-hub' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:22%">' . esc_html__( 'Name', 'neurohr-hub' ) . '</th><th style="width:16%">' . esc_html__( 'Address', 'neurohr-hub' ) . '</th><th>' . esc_html__( 'Description', 'neurohr-hub' ) . '</th></tr></thead><tbody>';
		foreach ( self::targets() as $slug => $t ) {
			$term = get_category_by_slug( $slug );
			$name = $term ? ( 'employment-laws' === $slug && 'Employment Laws' === $term->name ? $t[0] : $term->name ) : $t[0];
			$desc = $term && '' !== trim( $term->description ) ? $term->description : $t[1];
			echo '<tr><td><input type="text" class="widefat" name="cat[' . esc_attr( $slug ) . '][name]" value="' . esc_attr( $name ) . '"></td><td><code>' . esc_html( $slug ) . '</code>' . ( $term ? '' : ' <em>' . esc_html__( '(new)', 'neurohr-hub' ) . '</em>' ) . '</td><td><input type="text" class="widefat" name="cat[' . esc_attr( $slug ) . '][desc]" value="' . esc_attr( $desc ) . '"></td></tr>';
		}
		echo '</tbody></table>';

		$others = self::others();
		if ( $others ) {
			echo '<h3>' . esc_html__( 'Remove these categories', 'neurohr-hub' ) . '</h3><p class="description">' . esc_html__( 'Their old addresses keep working: they open the news home.', 'neurohr-hub' ) . '</p>';
			foreach ( $others as $c ) {
				echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="remove[]" value="' . esc_attr( $c->slug ) . '" ' . checked( 'hr-updates' === $c->slug, true, false ) . '> ' . esc_html( $c->name . ' (' . $c->count . ')' ) . '</label>';
			}
		}

		echo '<h2>' . esc_html__( '2. Posts', 'neurohr-hub' ) . '</h2><p class="description">' . esc_html__( 'One main category per post. Tags: separate with commas. "Newer update": for an older post on the same subject, readers see a link to the newer one at the top.', 'neurohr-hub' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Post', 'neurohr-hub' ) . '</th><th style="width:15%">' . esc_html__( 'Now', 'neurohr-hub' ) . '</th><th style="width:17%">' . esc_html__( 'Main category', 'neurohr-hub' ) . '</th><th style="width:22%">' . esc_html__( 'Tags', 'neurohr-hub' ) . '</th><th style="width:18%">' . esc_html__( 'Newer update', 'neurohr-hub' ) . '</th></tr></thead><tbody>';
		foreach ( $posts as $p ) {
			$cats    = get_the_category( $p->ID );
			$slugs   = wp_list_pluck( $cats, 'slug' );
			$suggest = self::suggest_category( $p->post_title, $slugs );
			$tags    = wp_list_pluck( (array) get_the_tags( $p->ID ), 'name' );
			$tags    = array_values( array_unique( array_merge( array_filter( $tags ), self::suggest_tags( $p->post_title ) ) ) );
			$cur_new = (int) get_post_meta( $p->ID, NH_Blog::NEWER, true );
			$sel_new = $cur_new ? $cur_new : ( isset( $newer[ $p->ID ] ) ? $newer[ $p->ID ] : 0 );
			echo '<tr><td><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '"><strong>' . esc_html( get_the_title( $p ) ) . '</strong></a><br><span class="description">' . esc_html( get_the_date( 'Y-m-d', $p ) ) . '</span></td>';
			echo '<td>' . esc_html( $cats ? implode( ', ', wp_list_pluck( $cats, 'name' ) ) : '—' ) . '</td>';
			echo '<td><select name="post[' . (int) $p->ID . '][cat]" style="width:100%"><option value="">' . esc_html__( '— Keep as is —', 'neurohr-hub' ) . '</option>';
			foreach ( self::targets() as $slug => $t ) {
				echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $suggest, $slug, false ) . '>' . esc_html( $t[0] ) . '</option>';
			}
			echo '</select></td>';
			echo '<td><input type="text" class="widefat" name="post[' . (int) $p->ID . '][tags]" value="' . esc_attr( implode( ', ', $tags ) ) . '"></td>';
			echo '<td><select name="post[' . (int) $p->ID . '][newer]" style="width:100%"><option value="0">' . esc_html__( '— None —', 'neurohr-hub' ) . '</option>';
			foreach ( $all as $o ) {
				if ( $o->ID !== $p->ID && $o->post_date > $p->post_date ) {
					echo '<option value="' . (int) $o->ID . '" ' . selected( $sel_new, $o->ID, false ) . '>' . esc_html( get_the_date( 'Y-m-d', $o ) . ' · ' . wp_trim_words( $o->post_title, 8 ) ) . '</option>';
				}
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Apply', 'neurohr-hub' ) );
		echo '</form></div>';
	}

	public static function apply() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'neurohr-hub' ) );
		}
		check_admin_referer( 'nh_news_tidy' );
		$result = self::run(
			isset( $_POST['cat'] ) ? (array) wp_unslash( $_POST['cat'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned in run().
			isset( $_POST['post'] ) ? (array) wp_unslash( $_POST['post'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			isset( $_POST['remove'] ) ? array_map( 'sanitize_title', (array) wp_unslash( $_POST['remove'] ) ) : array() // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
		wp_safe_redirect( self::url( array( 'done' => $result['posts'], 'removed' => $result['removed'] ) ) );
		exit;
	}

	/** Does the work (also used by the tests). */
	public static function run( array $cats, array $posts, array $remove ) {
		$ids = array();
		foreach ( self::targets() as $slug => $t ) {
			$name = isset( $cats[ $slug ]['name'] ) && '' !== trim( $cats[ $slug ]['name'] ) ? sanitize_text_field( $cats[ $slug ]['name'] ) : $t[0];
			$desc = isset( $cats[ $slug ]['desc'] ) ? sanitize_text_field( $cats[ $slug ]['desc'] ) : $t[1];
			$term = get_category_by_slug( $slug );
			if ( $term ) {
				wp_update_term( $term->term_id, 'category', array( 'name' => $name, 'description' => $desc ) );
				$ids[ $slug ] = (int) $term->term_id;
			} else {
				$new = wp_insert_term( $name, 'category', array( 'slug' => $slug, 'description' => $desc ) );
				if ( ! is_wp_error( $new ) ) {
					$ids[ $slug ] = (int) $new['term_id'];
				}
			}
		}
		$count = 0;
		foreach ( $posts as $id => $row ) {
			$id = absint( $id );
			if ( ! $id || 'post' !== get_post_type( $id ) ) {
				continue;
			}
			$slug = isset( $row['cat'] ) ? sanitize_title( $row['cat'] ) : '';
			if ( '' !== $slug && isset( $ids[ $slug ] ) ) {
				wp_set_post_categories( $id, array( $ids[ $slug ] ), false );
			}
			if ( isset( $row['tags'] ) ) {
				$tags = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $row['tags'] ) ) ) ) );
				wp_set_post_tags( $id, $tags, false );
			}
			$newer = isset( $row['newer'] ) ? absint( $row['newer'] ) : 0;
			if ( $newer && $newer !== $id && 'post' === get_post_type( $newer ) ) {
				update_post_meta( $id, NH_Blog::NEWER, $newer );
			} else {
				delete_post_meta( $id, NH_Blog::NEWER );
			}
			++$count;
		}
		$removed = 0;
		$gone    = (array) get_option( 'nh_news_old_categories', array() );
		foreach ( $remove as $slug ) {
			$term = get_category_by_slug( $slug );
			if ( $term && ! isset( self::targets()[ $slug ] ) && (int) $term->term_id !== (int) get_option( 'default_category' ) ) {
				wp_delete_term( $term->term_id, 'category' );
				$gone[] = $slug;
				++$removed;
			}
		}
		update_option( 'nh_news_old_categories', array_values( array_unique( $gone ) ), false );
		return array( 'posts' => $count, 'removed' => $removed );
	}
}
