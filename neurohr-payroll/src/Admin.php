<?php
/**
 * Admin screens under their own "NeuroHR Payroll" menu:
 *   Overview · PCB tables (yearly LHDN specification) · SOCSO & EPF rates · Settings
 */

namespace NeuroHR\Payroll;

use NeuroHR\Payroll\Engine\Money;
use NeuroHR\Payroll\Engine\Rates;
use NeuroHR\Payroll\Engine\SelfTest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	const CAP        = 'manage_options';
	const OPT_YEARS  = 'nhp_pcb_years';
	const OPT_L24    = 'nhp_l24_schedule';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_nhp_save_year', array( __CLASS__, 'save_year' ) );
		add_action( 'admin_post_nhp_add_year', array( __CLASS__, 'add_year' ) );
		add_action( 'admin_post_nhp_reset_year', array( __CLASS__, 'reset_year' ) );
		add_action( 'admin_post_nhp_save_l24', array( __CLASS__, 'save_l24' ) );
		add_action( 'admin_head', array( __CLASS__, 'styles' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NHP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( 'NeuroHR Payroll', 'NeuroHR Payroll', self::CAP, 'nhp', array( __CLASS__, 'page_overview' ), 'dashicons-calculator', 58 );
		add_submenu_page( 'nhp', 'Overview', 'Overview', self::CAP, 'nhp', array( __CLASS__, 'page_overview' ) );
		add_submenu_page( 'nhp', 'PCB tables', 'PCB tables', self::CAP, 'nhp-pcb', array( __CLASS__, 'page_pcb' ) );
		add_submenu_page( 'nhp', 'SOCSO & EPF rates', 'SOCSO & EPF rates', self::CAP, 'nhp-rates', array( __CLASS__, 'page_rates' ) );
		add_submenu_page( 'nhp', 'Settings', 'Settings', self::CAP, 'nhp-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=nhp' ) ) . '">Open</a>' );
		return $links;
	}

	public static function styles() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'nhp' ) ) {
			return;
		}
		?>
		<style>
			.nhp-admin .nhp-box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin:16px 0;max-width:1100px}
			.nhp-admin .nhp-box h2{margin-top:0}
			.nhp-admin .nhp-pass{color:#008a20;font-weight:600}
			.nhp-admin .nhp-fail{color:#d63638;font-weight:600}
			.nhp-admin .nhp-warn{border-left:4px solid #dba617}
			.nhp-admin .nhp-scroll{overflow-x:auto}
			.nhp-admin table.nhp-grid input{width:100%;min-width:70px}
			.nhp-admin table.nhp-grid td,.nhp-admin table.nhp-grid th{padding:6px}
			.nhp-admin .nhp-badge{display:inline-block;padding:1px 8px;border-radius:10px;background:#f0f0f1;font-size:12px}
			.nhp-admin .nhp-badge.edited{background:#fcf0e3;color:#8a4b00}
			.nhp-admin .nhp-badge.added{background:#e7f5ea;color:#00631a}
			.nhp-admin .nhp-steps li{margin-bottom:6px}
		</style>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Data helpers                                                        */
	/* ------------------------------------------------------------------ */

	private static function overrides() {
		$o = get_option( self::OPT_YEARS, array() );
		return is_array( $o ) ? $o : array();
	}

	private static function status( $year ) {
		$built = Rates::builtInPcbYears();
		$over  = self::overrides();
		if ( isset( $over[ $year ] ) ) {
			return isset( $built[ $year ] ) ? 'edited' : 'added';
		}
		return 'built-in';
	}

	private static function rm( $sen ) {
		return Money::format( (int) $sen );
	}

	/** RM text (may be negative) to sen. */
	private static function sen( $text ) {
		$t   = trim( str_replace( array( ',', 'RM', ' ' ), '', (string) $text ) );
		$neg = 0 === strpos( $t, '-' );
		$v   = Money::toSen( ltrim( $t, '-' ) );
		return $neg ? -$v : $v;
	}

	/** Percent text to basis points. */
	private static function bp( $text ) {
		return (int) round( (float) str_replace( '%', '', (string) $text ) * 100 );
	}

	private static function pct( $bp ) {
		return rtrim( rtrim( number_format( $bp / 100, 2, '.', '' ), '0' ), '.' );
	}

	/** Test field for display: amounts as 1,234.50, counts as typed. */
	private static function test_value( array $t, $key ) {
		if ( ! isset( $t[ $key ] ) || '' === $t[ $key ] ) {
			return '';
		}
		if ( in_array( $key, array( 'month', 'category', 'children' ), true ) ) {
			return $t[ $key ];
		}
		return self::rm( self::sen( $t[ $key ] ) );
	}

	private static function notice() {
		if ( empty( $_GET['nhp_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$msg = sanitize_text_field( wp_unslash( $_GET['nhp_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$map = array(
			'saved'   => array( 'success', 'Saved.' ),
			'added'   => array( 'success', 'Year added. Update the figures that changed and enter the new worked examples.' ),
			'reset'   => array( 'success', 'Year reset to the built-in figures.' ),
			'removed' => array( 'success', 'Year removed.' ),
			'exists'  => array( 'error', 'That year already exists.' ),
			'badyear' => array( 'error', 'Enter a year between 2025 and 2099.' ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
		}
	}

	private static function test_summary( array $results ) {
		if ( ! $results ) {
			return '<span class="description">No worked examples entered</span>';
		}
		$pass = count( array_filter( wp_list_pluck( $results, 'pass' ) ) );
		$all  = count( $results );
		$cls  = $pass === $all ? 'nhp-pass' : 'nhp-fail';
		return sprintf( '<span class="%s">%d of %d match</span>', $cls, $pass, $all );
	}

	/* ------------------------------------------------------------------ */
	/* Overview                                                            */
	/* ------------------------------------------------------------------ */

	public static function page_overview() {
		$year    = (int) current_time( 'Y' );
		$month   = (int) current_time( 'n' );
		$years   = Rates::pcbYears();
		$results = SelfTest::runAll();
		$pages   = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				's'              => '[nh_payroll_calculator',
				'posts_per_page' => 5,
			)
		);
		?>
		<div class="wrap nhp-admin">
			<h1>NeuroHR Payroll</h1>

			<?php if ( ! isset( $years[ $year ] ) ) : ?>
				<div class="notice notice-warning"><p>
					There is no <?php echo esc_html( $year ); ?> PCB table yet, so PCB uses the <?php echo esc_html( Rates::pcbYearFor( $year ) ); ?> figures.
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=nhp-pcb' ) ); ?>">Add <?php echo esc_html( $year ); ?></a> when LHDN publishes the specification.
				</p></div>
			<?php elseif ( $month >= 11 && ! isset( $years[ $year + 1 ] ) ) : ?>
				<div class="notice notice-info"><p>
					LHDN normally publishes the <?php echo esc_html( $year + 1 ); ?> MTD specification around December or January. Add it under
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=nhp-pcb' ) ); ?>">PCB tables</a> before the first <?php echo esc_html( $year + 1 ); ?> payroll.
				</p></div>
			<?php endif; ?>

			<div class="nhp-box">
				<h2>Calculator page</h2>
				<p>Put <code>[nh_payroll_calculator]</code> on a page (or <code>[nh_payroll_calculator title="no"]</code> without its heading). Logged-in members can use it; guests see a login button. Nothing members type is saved.</p>
				<?php if ( $pages ) : ?>
					<p>Used on:
					<?php
					$links = array();
					foreach ( $pages as $p ) {
						$links[] = '<a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a>';
					}
					echo wp_kses_post( implode( ', ', $links ) );
					?>
					</p>
				<?php else : ?>
					<p class="description">No page uses the shortcode yet.</p>
				<?php endif; ?>
			</div>

			<?php self::usage_box(); ?>

			<div class="nhp-box">
				<h2>PCB tables and checks</h2>
				<table class="widefat striped">
					<thead><tr><th>Year</th><th>Source</th><th>Worked examples</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $years as $y => $rules ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $y ); ?></strong> <span class="nhp-badge <?php echo esc_attr( self::status( $y ) ); ?>"><?php echo esc_html( self::status( $y ) ); ?></span></td>
							<td><?php echo esc_html( $rules['source'] ); ?></td>
							<td><?php echo wp_kses_post( self::test_summary( $results[ $y ] ) ); ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=nhp-pcb&year=' . $y ) ); ?>">Open</a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="nhp-box">
				<h2>Other rules in use</h2>
				<ul style="list-style:disc;padding-left:20px">
					<li>EPF: KWSP Third Schedule bands. Foreign workers 2% + 2% from October 2025 wages.</li>
					<li>SOCSO and EIS: PERKESO tables, wage ceiling RM6,000.</li>
					<li>LINDUNG 24 Jam: <?php echo esc_html( self::l24_text() ); ?> — <a href="<?php echo esc_url( admin_url( 'admin.php?page=nhp-rates' ) ); ?>">change</a>.</li>
					<li>PCB: SOCSO + EIS deducted from salary are claimed as relief each month, up to the year's limit, as in LHDN's own calculator.</li>
				</ul>
			</div>
		</div>
		<?php
	}

	private static function usage_box() {
		$stats  = get_option( Plugin::OPT_STATS, array() );
		$stats  = is_array( $stats ) ? array_slice( $stats, 0, 6, true ) : array();
		$levels = array(
			'free'      => 'Free',
			'lite'      => 'Lite',
			'pro'       => 'Pro',
			'corporate' => 'Corporate',
		);
		if ( Plugin::members_active() ) {
			$levels = array();
			foreach ( nhm_levels() as $level ) {
				$levels[ $level['slug'] ] = $level['name'];
			}
		}
		$levels['staff'] = 'Staff';
		$sum = function ( $row ) {
			return is_array( $row ) ? array_sum( $row ) : 0;
		};
		?>
		<div class="nhp-box">
			<h2>Usage</h2>
			<?php if ( ! $stats ) : ?>
				<p class="description">No use recorded yet. Each member is counted once a month when they open the calculator; printed payslips and "Next month" clicks are counted every time.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Month</th>
							<?php foreach ( $levels as $label ) : ?><th><?php echo esc_html( $label ); ?></th><?php endforeach; ?>
							<th>Members</th><th>Payslips printed</th><th>Next month used</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $stats as $month => $row ) : ?>
						<tr>
							<td><?php echo esc_html( date( 'M Y', strtotime( $month . '-01' ) ) ); ?></td>
							<?php foreach ( array_keys( $levels ) as $key ) : ?>
								<td><?php echo (int) ( isset( $row['use'][ $key ] ) ? $row['use'][ $key ] : 0 ); ?></td>
							<?php endforeach; ?>
							<td><strong><?php echo (int) $sum( isset( $row['use'] ) ? $row['use'] : array() ); ?></strong></td>
							<td><?php echo (int) $sum( isset( $row['print'] ) ? $row['print'] : array() ); ?></td>
							<td><?php echo (int) $sum( isset( $row['next'] ) ? $row['next'] : array() ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Members by level = members who used the calculator that month.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function l24_text() {
		$parts = array();
		foreach ( Rates::l24Schedule() as $row ) {
			$parts[] = self::pct( $row['bp'] ) . '% from ' . date( 'M Y', strtotime( $row['from'] . '-01' ) );
		}
		return $parts ? implode( ', ', $parts ) : 'not deducted';
	}

	/* ------------------------------------------------------------------ */
	/* PCB tables                                                          */
	/* ------------------------------------------------------------------ */

	public static function page_pcb() {
		$years = Rates::pcbYears();
		$year  = isset( $_GET['year'] ) ? (int) $_GET['year'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $year && isset( $years[ $year ] ) ) {
			self::edit_year( $year, $years[ $year ] );
			return;
		}
		$results = SelfTest::runAll();
		$latest  = max( array_keys( $years ) );
		?>
		<div class="wrap nhp-admin">
			<h1>PCB tables</h1>
			<?php self::notice(); ?>
			<p>Each year LHDN publishes the <em>Specification for Monthly Tax Deduction (MTD) Calculations Using Computerised Calculation</em>.
			Add the new year here, copy the figures that changed, and type in the worked examples from the document. When every example matches, the year is ready.</p>

			<div class="nhp-box">
				<table class="widefat striped">
					<thead><tr><th>Year</th><th>Source</th><th>Worked examples</th><th>Actions</th></tr></thead>
					<tbody>
					<?php foreach ( $years as $y => $rules ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $y ); ?></strong> <span class="nhp-badge <?php echo esc_attr( self::status( $y ) ); ?>"><?php echo esc_html( self::status( $y ) ); ?></span></td>
							<td><?php echo esc_html( $rules['source'] ); ?></td>
							<td><?php echo wp_kses_post( self::test_summary( $results[ $y ] ) ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=nhp-pcb&year=' . $y ) ); ?>">Edit</a>
								<?php if ( 'built-in' !== self::status( $y ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( 'edited' === self::status( $y ) ? 'Go back to the built-in figures for this year?' : 'Remove this year?' ); ?>')">
										<?php wp_nonce_field( 'nhp_reset_year' ); ?>
										<input type="hidden" name="action" value="nhp_reset_year">
										<input type="hidden" name="year" value="<?php echo esc_attr( $y ); ?>">
										<button class="button button-small button-link-delete"><?php echo 'edited' === self::status( $y ) ? 'Reset' : 'Remove'; ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="nhp-box">
				<h2>Add a year</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'nhp_add_year' ); ?>
					<input type="hidden" name="action" value="nhp_add_year">
					<label>Year <input type="number" name="year" value="<?php echo esc_attr( $latest + 1 ); ?>" min="2025" max="2099" class="small-text"></label>
					<label style="margin-left:12px">Copy figures from
						<select name="from">
							<?php foreach ( array_reverse( array_keys( $years ) ) as $y ) : ?>
								<option value="<?php echo esc_attr( $y ); ?>"><?php echo esc_html( $y ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<?php submit_button( 'Add year', 'primary', 'submit', false, array( 'style' => 'margin-left:12px' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	private static function edit_year( $year, array $r ) {
		$tests    = $r['tests'];
		$fields   = SelfTest::fields();
		$table    = $r['table'];
		$blankRow = array( '', '', '', '' );
		while ( count( $table ) < 12 ) {
			$table[] = $blankRow;
		}
		$results = array();
		foreach ( $tests as $t ) {
			$results[] = SelfTest::run( $t, $r );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$tests[] = array();
		}
		$money = array(
			'relief_self'        => 'Individual (D)',
			'relief_spouse'      => 'Spouse not working (S)',
			'relief_disabled'    => 'Disabled individual (DU)',
			'relief_dis_spouse'  => 'Disabled spouse (SU)',
			'relief_child'       => 'Each child (Q)',
			'epf_relief_limit'   => 'EPF relief limit per year',
			'socso_relief_limit' => 'SOCSO + EIS relief limit per year',
			'l24_relief_limit'   => 'LINDUNG 24 Jam relief limit per year',
			'min_mtd'            => 'Do not deduct MTD below',
		);
		?>
		<div class="wrap nhp-admin">
			<h1>PCB <?php echo esc_html( $year ); ?> <span class="nhp-badge <?php echo esc_attr( self::status( $year ) ); ?>"><?php echo esc_html( self::status( $year ) ); ?></span></h1>
			<?php self::notice(); ?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=nhp-pcb' ) ); ?>">&larr; All years</a></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'nhp_save_year' ); ?>
				<input type="hidden" name="action" value="nhp_save_year">
				<input type="hidden" name="year" value="<?php echo esc_attr( $year ); ?>">

				<div class="nhp-box">
					<h2>Source</h2>
					<input type="text" name="source" value="<?php echo esc_attr( $r['source'] ); ?>" class="large-text" placeholder="LHDN Specification for MTD Calculations Using Computerised Calculation for <?php echo esc_attr( $year ); ?>">
				</div>

				<div class="nhp-box">
					<h2>Table 1 — tax rates (RM)</h2>
					<p class="description">One row per band, as printed: <strong>P</strong> above <strong>M</strong>, rate <strong>R</strong>, and <strong>B</strong> for category 1 &amp; 3 and for category 2 (B already includes the rebates, so it can be negative). Leave unused rows empty.</p>
					<table class="widefat nhp-grid" style="max-width:720px">
						<thead><tr><th>M (RM)</th><th>R (%)</th><th>B category 1 &amp; 3 (RM)</th><th>B category 2 (RM)</th></tr></thead>
						<tbody>
						<?php foreach ( $table as $i => $row ) : ?>
							<?php $blank = '' === $row[0]; ?>
							<tr>
								<td><input type="text" name="table[<?php echo (int) $i; ?>][0]" value="<?php echo esc_attr( $blank ? '' : self::rm( $row[0] ) ); ?>"></td>
								<td><input type="text" name="table[<?php echo (int) $i; ?>][1]" value="<?php echo esc_attr( $blank ? '' : self::pct( $row[1] ) ); ?>"></td>
								<td><input type="text" name="table[<?php echo (int) $i; ?>][2]" value="<?php echo esc_attr( $blank ? '' : self::rm( $row[2] ) ); ?>"></td>
								<td><input type="text" name="table[<?php echo (int) $i; ?>][3]" value="<?php echo esc_attr( $blank ? '' : self::rm( $row[3] ) ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="nhp-box">
					<h2>Reliefs and limits (RM)</h2>
					<table class="form-table" role="presentation">
						<?php foreach ( $money as $key => $label ) : ?>
							<tr>
								<th scope="row"><label for="nhp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
								<td><input type="text" id="nhp-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( self::rm( $r[ $key ] ) ); ?>" class="regular-text"></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th scope="row"><label for="nhp-nr">Non-resident rate (%)</label></th>
							<td><input type="text" id="nhp-nr" name="non_resident_bp" value="<?php echo esc_attr( self::pct( $r['non_resident_bp'] ) ); ?>" class="small-text"></td>
						</tr>
					</table>
				</div>

				<div class="nhp-box">
					<h2>Worked examples from the specification</h2>
					<p class="description">Type each example as printed (amounts in RM). Leave fields empty when they are 0. Empty rows are ignored.</p>
					<div class="nhp-scroll">
						<table class="widefat striped nhp-grid">
							<thead><tr><th>Label</th><th>Result</th><?php foreach ( $fields as $label ) : ?><th><?php echo esc_html( $label ); ?></th><?php endforeach; ?></tr></thead>
							<tbody>
							<?php foreach ( $tests as $i => $t ) : ?>
								<tr>
									<td><input type="text" name="tests[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( isset( $t['label'] ) ? $t['label'] : '' ); ?>" style="min-width:150px"></td>
									<td style="white-space:nowrap">
										<?php if ( isset( $results[ $i ] ) ) : ?>
											<?php if ( $results[ $i ]['pass'] ) : ?>
												<span class="nhp-pass">✓ <?php echo esc_html( self::rm( $results[ $i ]['got'] ) ); ?></span>
											<?php else : ?>
												<span class="nhp-fail">✗ got <?php echo esc_html( self::rm( $results[ $i ]['got'] ) ); ?></span>
											<?php endif; ?>
										<?php endif; ?>
									</td>
									<?php foreach ( $fields as $key => $label ) : ?>
										<td><input type="text" name="tests[<?php echo (int) $i; ?>][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( self::test_value( $t, $key ) ); ?>"></td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>

				<?php submit_button( 'Save and check' ); ?>
			</form>

			<div class="nhp-box">
				<h2>Yearly update steps</h2>
				<ol class="nhp-steps">
					<li>Download the new specification from LHDN (Employers → Employer (Payroll) Data Specification → MTD <?php echo esc_html( $year ); ?>).</li>
					<li>Add the year (copy from the previous year), then compare Table 1 and the deduction list with the document and change what differs.</li>
					<li>Type in its worked examples (January, February, March, the bonus example). Save — every row should show ✓.</li>
					<li>If an example does not match, check the figures you typed first; if they are right, the method itself changed — ask your developer.</li>
				</ol>
			</div>
		</div>
		<?php
	}

	private static function check( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( $action );
	}

	private static function back( $args, $page = 'nhp-pcb' ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	public static function save_year() {
		self::check( 'nhp_save_year' );
		$year = isset( $_POST['year'] ) ? (int) $_POST['year'] : 0;
		if ( $year < 2025 || $year > 2099 ) {
			self::back( array( 'nhp_msg' => 'badyear' ) );
		}
		$post = wp_unslash( $_POST );

		$rules = array( 'source' => isset( $post['source'] ) ? sanitize_text_field( $post['source'] ) : '' );

		$rows = array();
		if ( isset( $post['table'] ) && is_array( $post['table'] ) ) {
			foreach ( $post['table'] as $row ) {
				if ( ! is_array( $row ) || '' === trim( (string) ( isset( $row[0] ) ? $row[0] : '' ) ) ) {
					continue;
				}
				$rows[] = array(
					self::sen( $row[0] ),
					self::bp( isset( $row[1] ) ? $row[1] : '0' ),
					self::sen( isset( $row[2] ) ? $row[2] : '0' ),
					self::sen( isset( $row[3] ) ? $row[3] : '0' ),
				);
			}
		}
		$rules['table'] = $rows;

		foreach ( array( 'relief_self', 'relief_spouse', 'relief_disabled', 'relief_dis_spouse', 'relief_child', 'epf_relief_limit', 'socso_relief_limit', 'l24_relief_limit', 'min_mtd' ) as $k ) {
			$rules[ $k ] = isset( $post[ $k ] ) ? self::sen( $post[ $k ] ) : 0;
		}
		$rules['non_resident_bp'] = isset( $post['non_resident_bp'] ) ? self::bp( $post['non_resident_bp'] ) : 3000;

		$tests = array();
		if ( isset( $post['tests'] ) && is_array( $post['tests'] ) ) {
			foreach ( $post['tests'] as $t ) {
				if ( ! is_array( $t ) ) {
					continue;
				}
				$clean = array( 'label' => isset( $t['label'] ) ? sanitize_text_field( $t['label'] ) : '' );
				$any   = false;
				foreach ( array_keys( SelfTest::fields() ) as $k ) {
					$v = isset( $t[ $k ] ) ? trim( sanitize_text_field( $t[ $k ] ) ) : '';
					if ( '' !== $v ) {
						$clean[ $k ] = $v;
						$any         = true;
					}
				}
				if ( $any ) {
					$tests[] = $clean;
				}
			}
		}
		$rules['tests'] = $tests;

		$over          = self::overrides();
		$over[ $year ] = Rates::cleanPcbRules( $rules, $year );
		update_option( self::OPT_YEARS, $over, false );
		self::back( array( 'year' => $year, 'nhp_msg' => 'saved' ) );
	}

	public static function add_year() {
		self::check( 'nhp_add_year' );
		$year  = isset( $_POST['year'] ) ? (int) $_POST['year'] : 0;
		$from  = isset( $_POST['from'] ) ? (int) $_POST['from'] : 0;
		$years = Rates::pcbYears();
		if ( $year < 2025 || $year > 2099 ) {
			self::back( array( 'nhp_msg' => 'badyear' ) );
		}
		if ( isset( $years[ $year ] ) ) {
			self::back( array( 'nhp_msg' => 'exists' ) );
		}
		$copy           = isset( $years[ $from ] ) ? $years[ $from ] : end( $years );
		$copy['source'] = 'LHDN Specification for MTD Calculations Using Computerised Calculation for ' . $year;
		$copy['tests']  = array();
		$over           = self::overrides();
		$over[ $year ]  = $copy;
		update_option( self::OPT_YEARS, $over, false );
		self::back( array( 'year' => $year, 'nhp_msg' => 'added' ) );
	}

	public static function reset_year() {
		self::check( 'nhp_reset_year' );
		$year  = isset( $_POST['year'] ) ? (int) $_POST['year'] : 0;
		$over  = self::overrides();
		$built = Rates::builtInPcbYears();
		unset( $over[ $year ] );
		update_option( self::OPT_YEARS, $over, false );
		self::back( array( 'nhp_msg' => isset( $built[ $year ] ) ? 'reset' : 'removed' ) );
	}

	/* ------------------------------------------------------------------ */
	/* SOCSO & EPF rates                                                   */
	/* ------------------------------------------------------------------ */

	public static function page_rates() {
		$rows = Rates::l24Schedule();
		$rows[] = array( 'from' => '', 'bp' => '' );
		$rows[] = array( 'from' => '', 'bp' => '' );
		?>
		<div class="wrap nhp-admin">
			<h1>SOCSO &amp; EPF rates</h1>
			<?php self::notice(); ?>

			<div class="nhp-box">
				<h2>LINDUNG 24 Jam (employee rate)</h2>
				<p>PERKESO raises the rate in phases (0.75% → 1.00% → 1.25%) and announces each date. Add a row when a new phase is announced; it applies from that pay month.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'nhp_save_l24' ); ?>
					<input type="hidden" name="action" value="nhp_save_l24">
					<table class="widefat nhp-grid" style="max-width:420px">
						<thead><tr><th>From pay month</th><th>Employee rate (%)</th></tr></thead>
						<tbody>
						<?php foreach ( $rows as $i => $row ) : ?>
							<tr>
								<td><input type="month" name="l24[<?php echo (int) $i; ?>][from]" value="<?php echo esc_attr( $row['from'] ); ?>"></td>
								<td><input type="text" name="l24[<?php echo (int) $i; ?>][rate]" value="<?php echo esc_attr( '' === $row['bp'] ? '' : self::pct( $row['bp'] ) ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description">Empty rows are ignored. Rate 0 stops the deduction from that month.</p>
					<?php submit_button( 'Save' ); ?>
				</form>
			</div>

			<div class="nhp-box">
				<h2>Built-in rules (change in a plugin update)</h2>
				<ul style="list-style:disc;padding-left:20px">
					<li>EPF Third Schedule: RM20 bands up to RM5,000, RM100 bands up to RM20,000, exact percentage above; each share rounded up to the ringgit.</li>
					<li>EPF Auto rates: below 60 — 11% / 13% (12% above RM5,000); Malaysian 60+ — 0% / 4%; PR 60+ — 5.5% / 6.5% (6% above RM5,000); foreign — 2% / 2% from Oct 2025; 75+ — none.</li>
					<li>SOCSO: first category 1.75% / 0.5%, second category 1.25% employer only; EIS 0.2% / 0.2% (ages 18–59, not foreign); ceiling RM6,000 for both.</li>
				</ul>
			</div>
		</div>
		<?php
	}

	public static function save_l24() {
		self::check( 'nhp_save_l24' );
		$rows = array();
		$in   = isset( $_POST['l24'] ) && is_array( $_POST['l24'] ) ? wp_unslash( $_POST['l24'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $in as $row ) {
			$from = isset( $row['from'] ) ? sanitize_text_field( $row['from'] ) : '';
			$rate = isset( $row['rate'] ) ? trim( sanitize_text_field( $row['rate'] ) ) : '';
			if ( ! preg_match( '/^\d{4}-\d{2}$/', $from ) || '' === $rate ) {
				continue;
			}
			$rows[] = array( 'from' => $from, 'bp' => max( 0, min( 1000, self::bp( $rate ) ) ) );
		}
		update_option( self::OPT_L24, $rows, false );
		self::back( array( 'nhp_msg' => 'saved' ), 'nhp-rates' );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function register_settings() {
		register_setting(
			'nhp_settings_group',
			Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'NeuroHR\\Payroll\\Plugin', 'sanitize_settings' ),
			)
		);
	}

	/** MemberPress memberships as ID => title (empty if MemberPress is not active). */
	private static function memberships() {
		if ( ! post_type_exists( 'memberpressproduct' ) ) {
			return array();
		}
		$out   = array();
		$posts = get_posts(
			array(
				'post_type'      => 'memberpressproduct',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $p ) {
			$out[ (int) $p->ID ] = get_the_title( $p );
		}
		return $out;
	}

	public static function page_settings() {
		$s   = Plugin::settings();
		$opt = Plugin::OPTION;
		?>
		<div class="wrap nhp-admin">
			<h1>Settings</h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'nhp_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nhp-brand">Brand colour</label></th>
						<td><input type="text" id="nhp-brand" name="<?php echo esc_attr( $opt ); ?>[brand]" value="<?php echo esc_attr( $s['brand'] ); ?>" class="regular-text" placeholder="#f26522">
						<p class="description">Same orange as the Knowledge Hub. Used on the calculator and the payslip.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="nhp-note">Payslip footer</label></th>
						<td><input type="text" id="nhp-note" name="<?php echo esc_attr( $opt ); ?>[payslip_note]" value="<?php echo esc_attr( $s['payslip_note'] ); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="nhp-disc">Disclaimer under the results</label></th>
						<td><input type="text" id="nhp-disc" name="<?php echo esc_attr( $opt ); ?>[disclaimer]" value="<?php echo esc_attr( $s['disclaimer'] ); ?>" class="large-text"></td>
					</tr>
				</table>

				<h2>Upgrade link</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nhp-up">Upgrade page</label></th>
						<td><input type="url" id="nhp-up" name="<?php echo esc_attr( $opt ); ?>[upgrade_url]" value="<?php echo esc_attr( $s['upgrade_url'] ); ?>" class="regular-text" placeholder="https://www.neurohr.com.my/pricing/">
						<p class="description">Where the <strong>Upgrade</strong> button goes. Members below the payslip-extras level see it next to those options. <?php echo Plugin::members_active() ? 'Leave empty to use the upgrade link of that level in NeuroHR Members.' : 'Leave empty to hide the button.'; ?></p></td>
					</tr>
				</table>

				<h2>Membership levels</h2>
				<?php if ( Plugin::members_active() ) : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nhp-pro-level">Payslip extras for</label></th>
							<td>
								<select id="nhp-pro-level" name="<?php echo esc_attr( $opt ); ?>[pro_level]">
									<?php $nhp_max = function_exists( 'nhm_max_rank' ) ? nhm_max_rank() : 0; ?>
									<?php foreach ( nhm_levels() as $nhp_level ) : ?>
										<option value="<?php echo (int) $nhp_level['id']; ?>" <?php selected( (int) $s['pro_level'], (int) $nhp_level['id'] ); ?>><?php echo esc_html( $nhp_level['name'] . ( $nhp_level['rank'] < $nhp_max ? ' and above' : '' ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">Logo, company address and pay date on the payslip. Levels come from <a href="<?php echo esc_url( admin_url( 'admin.php?page=nhm-levels' ) ); ?>">NeuroHR Members</a>. Any logged-in user can use the calculator; editors and admins have everything.</p>
							</td>
						</tr>
					</table>
					<?php foreach ( array( 'lite_ids', 'pro_ids', 'corp_ids' ) as $nhp_key ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $opt . '[' . $nhp_key . ']' ); ?>" value="<?php echo esc_attr( $s[ $nhp_key ] ); ?>">
					<?php endforeach; ?>
				<?php else : ?>
				<p class="description">Which MemberPress memberships count as Lite, Pro and Corporate (same IDs as the Knowledge Hub). Pro and Corporate members can add a logo, company address and pay date to the payslip. Any other logged-in user counts as Free; editors and admins have everything.</p>
				<?php $memberships = self::memberships(); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( array( 'lite_ids' => 'Lite', 'pro_ids' => 'Pro', 'corp_ids' => 'Corporate / Consulting' ) as $key => $label ) : ?>
						<?php $chosen = array_map( 'intval', preg_split( '/[^0-9]+/', (string) $s[ $key ], -1, PREG_SPLIT_NO_EMPTY ) ); ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<?php if ( $memberships ) : ?>
									<fieldset>
										<?php foreach ( $memberships as $id => $title ) : ?>
											<label style="display:block;margin-bottom:4px">
												<input type="checkbox" name="<?php echo esc_attr( $opt . '[' . $key . '][]' ); ?>" value="<?php echo (int) $id; ?>" <?php checked( in_array( (int) $id, $chosen, true ) ); ?>>
												<?php echo esc_html( $title ); ?> <span class="description">(ID <?php echo (int) $id; ?>)</span>
											</label>
										<?php endforeach; ?>
										<?php foreach ( array_diff( $chosen, array_keys( $memberships ) ) as $missing ) : ?>
											<input type="hidden" name="<?php echo esc_attr( $opt . '[' . $key . '][]' ); ?>" value="<?php echo (int) $missing; ?>">
											<p class="description">ID <?php echo (int) $missing; ?> is set but not found in MemberPress.</p>
										<?php endforeach; ?>
									</fieldset>
								<?php else : ?>
									<input type="text" name="<?php echo esc_attr( $opt . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>" class="regular-text">
									<p class="description">MemberPress membership IDs, comma separated.</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[pro_level]" value="<?php echo (int) $s['pro_level']; ?>">
				<?php endif; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
