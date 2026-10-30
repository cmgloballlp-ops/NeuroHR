<?php
/**
 * PDF invoices with dompdf (bundled in vendor/dompdf, LGPL). Temporary and font-cache files
 * go to wp-content/uploads/nhm-pdf/ (closed to the web).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NHM_PDF {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'member_download' ), 1 );
	}

	public static function temp_dir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'nhm-pdf';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			@file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/** @return string|WP_Error PDF bytes. */
	public static function render( $html ) {
		if ( ! class_exists( 'Dompdf\\Dompdf' ) ) {
			require_once NHM_DIR . 'vendor/dompdf/autoload.inc.php';
		}
		$dir     = self::temp_dir();
		$options = new Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isPhpEnabled', false );
		$options->set( 'isJavascriptEnabled', false );
		$options->set( 'isFontSubsettingEnabled', true );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'tempDir', $dir );
		$options->set( 'fontCache', $dir );
		$options->set( 'fontDir', $dir );
		$options->set( 'chroot', array( NHM_DIR, $dir ) );
		try {
			$pdf = new Dompdf\Dompdf( $options );
			$pdf->loadHtml( $html, 'UTF-8' );
			$pdf->setPaper( 'A4', 'portrait' );
			$pdf->render();
			return $pdf->output();
		} catch ( Throwable $e ) {
			return new WP_Error( 'nhm_pdf', $e->getMessage() );
		}
	}

	public static function filename( $inv ) {
		return sanitize_file_name( $inv['number'] ? $inv['number'] : 'invoice-' . $inv['id'] ) . '.pdf';
	}

	/** Write the PDF to a temporary file (for email attachments). Caller deletes it. */
	public static function invoice_file( $inv ) {
		$bytes = self::render( self::invoice_html( $inv ) );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		$sub = self::temp_dir() . '/' . wp_generate_password( 16, false, false );
		wp_mkdir_p( $sub );
		$path = $sub . '/' . self::filename( $inv );
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $path;
	}

	public static function cleanup( $path ) {
		if ( $path && is_string( $path ) && file_exists( $path ) ) {
			wp_delete_file( $path );
			@rmdir( dirname( $path ) ); // phpcs:ignore
		}
	}

	public static function send( $inv, $inline = true ) {
		$bytes = self::render( self::invoice_html( $inv ) );
		if ( is_wp_error( $bytes ) ) {
			wp_die( esc_html( $bytes->get_error_message() ), 500 );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . self::filename( $inv ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF.
		exit;
	}

	/** /?nhm_invoice=ID&key=… — opens with the secret key from the invoice link. */
	public static function member_download() {
		if ( empty( $_GET['nhm_invoice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id  = absint( $_GET['nhm_invoice'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$inv = NHM_Invoices::get( $id );
		// The secret key in the link is enough (like the payment link): the accounts team of a
		// company, or someone who signed up and has not logged in yet, can open it.
		if ( $inv && '' !== $key && hash_equals( (string) $inv['token'], $key ) ) {
			self::send( $inv );
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( NHM_Auth::login_url( NHM_Forms::current_url() ) );
			exit;
		}
		if ( ! $inv || ! NHM_Invoices::can_view( $inv ) ) {
			wp_die( esc_html__( 'This invoice is not available for your account.', 'neurohr-members' ), 403 );
		}
		self::send( $inv );
	}

	/** Logo as a data URI (dompdf may not fetch URLs). Only files inside uploads or the plugin. */
	private static function logo_src() {
		return self::image_src( NHM_Settings::get( 'biz_logo' ) );
	}

	/** DuitNow QR picture as a data URI when the QR payment is switched on. */
	private static function qr_src() {
		if ( ! NHM_Settings::get( 'pay_qr' ) ) {
			return '';
		}
		return self::image_src( NHM_Settings::get( 'qr_image' ) );
	}

	/** An uploads image as a data URI. */
	private static function image_src( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$up   = wp_upload_dir();
		$path = '';
		if ( 0 === strpos( $url, $up['baseurl'] ) ) {
			$path = $up['basedir'] . substr( $url, strlen( $up['baseurl'] ) );
		}
		$path = $path ? realpath( $path ) : '';
		if ( ! $path || 0 !== strpos( $path, realpath( $up['basedir'] ) ) || ! is_readable( $path ) ) {
			return '';
		}
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$mime = array( 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif' );
		if ( ! isset( $mime[ $ext ] ) || filesize( $path ) > 2 * MB_IN_BYTES ) {
			return '';
		}
		return 'data:' . $mime[ $ext ] . ';base64,' . base64_encode( file_get_contents( $path ) ); // phpcs:ignore
	}

	private static function e( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	private static function lines( array $lines ) {
		return implode( '<br>', array_map( array( __CLASS__, 'e' ), array_filter( $lines, 'strlen' ) ) );
	}

	public static function invoice_html( $inv ) {
		$s      = NHM_Settings::all();
		$brand  = NHM_Settings::brand();
		$e      = array( __CLASS__, 'e' );
		$money  = function ( $n ) use ( $inv ) {
			return self::e( trim( $inv['currency'] . ' ' . number_format( (float) $n, 2, '.', ',' ) ) );
		};
		$biz    = $s['biz_name'] ? $s['biz_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$addr   = array_filter( array_map( 'trim', explode( "\n", (string) $s['biz_address'] ) ) );
		$city   = trim( $s['biz_postcode'] . ' ' . $s['biz_city'] );
		$region = trim( implode( ', ', array_filter( array( $s['biz_state'], $s['biz_country'] ) ) ) );
		$from   = array_merge( $addr, array( $city, $region ) );
		$ids    = array();
		if ( $s['biz_reg'] ) {
			$ids[] = 'Reg. No.: ' . $s['biz_reg'];
		}
		if ( $s['biz_tin'] ) {
			$ids[] = 'TIN: ' . $s['biz_tin'];
		}
		if ( $s['biz_sst_no'] && $inv['tax_rate'] > 0 ) {
			$ids[] = 'SST No.: ' . $s['biz_sst_no'];
		}
		$contact = array_filter( array( $s['biz_phone'], $s['biz_email'], $s['biz_website'] ) );
		$b       = $inv['bill_to'];
		$bill    = array();
		if ( ! empty( $b['attention'] ) ) {
			$bill[] = 'Attn: ' . $b['attention'];
		}
		$bill = array_merge( $bill, isset( $b['address'] ) ? (array) $b['address'] : array() );
		$bids = array();
		foreach ( array( 'code' => 'Client code', 'reg_no' => 'Reg. No.', 'tin' => 'TIN', 'sst_no' => 'SST No.' ) as $k => $label ) {
			if ( ! empty( $b[ $k ] ) ) {
				$bids[] = $label . ': ' . $b[ $k ];
			}
		}
		$bcontact = array_filter( array( isset( $b['email'] ) ? $b['email'] : '', isset( $b['phone'] ) ? $b['phone'] : '' ) );
		$paid     = 'paid' === $inv['status'];
		$void     = 'void' === $inv['status'];
		$logo     = self::logo_src();
		$qr       = $paid || $void ? '' : self::qr_src();
		$title    = $inv['tax_rate'] > 0 ? 'TAX INVOICE' : 'INVOICE';
		$fmt      = function ( $d ) {
			return $d ? self::e( wp_date( 'j M Y', strtotime( $d . ' 12:00:00' ) ) ) : '';
		};

		ob_start();
		?><!doctype html>
<html><head><meta charset="utf-8"><style>
@page { margin: 34px 40px 60px; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #1d2330; line-height: 1.45; }
.bar { height: 5px; background: <?php echo $e( $brand ); ?>; margin: 0 0 18px; }
table { border-collapse: collapse; width: 100%; }
.head td { vertical-align: top; }
.biz { font-size: 13pt; font-weight: bold; }
.muted { color: #6b7280; }
.small { font-size: 8.3pt; }
.title { font-size: 20pt; font-weight: bold; letter-spacing: 1px; color: <?php echo $e( $brand ); ?>; text-align: right; }
.meta td { padding: 1px 0; }
.meta td.k { color: #6b7280; padding-right: 10px; text-align: right; }
.meta td.v { text-align: right; font-weight: bold; }
.box { margin: 22px 0 16px; }
.box h3 { font-size: 8pt; letter-spacing: 1px; color: #6b7280; margin: 0 0 4px; text-transform: uppercase; }
.items th { background: #f5f6f8; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .5px; color: #4b5563; padding: 7px 8px; border-bottom: 1px solid #e5e7eb; }
.items td { padding: 8px; border-bottom: 1px solid #eef0f3; vertical-align: top; }
.num, .items th.num { text-align: right; white-space: nowrap; }
.pay td { vertical-align: top; }
.qr { text-align: center; width: 150px; padding-left: 14px; }
.qr img { width: 132px; height: 132px; }
.totals { width: 46%; margin-left: 54%; margin-top: 10px; }
.totals td { padding: 4px 8px; }
.totals tr.grand td { font-size: 12pt; font-weight: bold; border-top: 2px solid #1d2330; padding-top: 7px; }
.st-paid { color: #15803d; }
.st-void { color: #b91c1c; }
.st-unpaid { color: #b45309; }
.period { margin-top: 6px; font-size: 8.5pt; color: #4b5563; }
.paid { margin-top: 14px; padding: 8px 10px; background: #f0fdf4; border: 1px solid #bbf7d0; }
.pay { margin-top: 18px; padding: 10px 12px; background: #f5f6f8; }
.foot { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 7.5pt; color: #8b909a; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 6px; }
</style></head><body>
<div class="bar"></div>
<table class="head"><tr>
	<td style="width:58%">
		<?php if ( $logo ) : ?><img src="<?php echo $e( $logo ); ?>" style="max-height:58px;max-width:200px;margin-bottom:8px"><br><?php endif; ?>
		<span class="biz"><?php echo $e( $biz ); ?></span><br>
		<?php if ( $ids ) : ?><span class="small muted"><?php echo self::lines( $ids ); ?></span><br><?php endif; ?>
		<span class="small"><?php echo self::lines( $from ); ?></span>
		<?php if ( $contact ) : ?><br><span class="small muted"><?php echo self::lines( $contact ); ?></span><?php endif; ?>
	</td>
	<td style="width:42%">
		<div class="title"><?php echo $e( $title ); ?></div>
		<table class="meta" style="margin-top:8px">
			<tr><td class="k">Invoice no.</td><td class="v"><?php echo $e( $inv['number'] ); ?></td></tr>
			<tr><td class="k">Date</td><td class="v"><?php echo $fmt( $inv['issue_date'] ); ?></td></tr>
			<?php if ( ! $paid && ! $void && $inv['due_date'] ) : ?><tr><td class="k">Due date</td><td class="v"><?php echo $fmt( $inv['due_date'] ); ?></td></tr><?php endif; ?>
			<?php if ( $paid ) : ?><tr><td class="k">Paid on</td><td class="v"><?php echo $fmt( $inv['paid_date'] ); ?></td></tr><?php endif; ?>
			<tr><td class="k">Status</td><td class="v <?php echo $paid ? 'st-paid' : ( $void ? 'st-void' : 'st-unpaid' ); ?>"><?php echo $paid ? 'Paid' : ( $void ? 'Void' : 'Unpaid' ); ?></td></tr>
		</table>
	</td>
</tr></table>

<div class="box">
	<h3>Bill to</h3>
	<strong><?php echo $e( isset( $b['name'] ) ? $b['name'] : '' ); ?></strong><br>
	<?php if ( $bill ) : ?><?php echo self::lines( $bill ); ?><br><?php endif; ?>
	<?php if ( $bids ) : ?><span class="small muted"><?php echo self::lines( $bids ); ?></span><br><?php endif; ?>
	<?php if ( $bcontact ) : ?><span class="small muted"><?php echo self::lines( $bcontact ); ?></span><?php endif; ?>
</div>

<table class="items">
	<thead><tr><th style="width:5%">#</th><th>Description</th><th class="num" style="width:9%">Qty</th><th class="num" style="width:17%">Unit price</th><th class="num" style="width:17%">Amount</th></tr></thead>
	<tbody>
	<?php foreach ( $inv['items'] as $i => $it ) : ?>
		<tr><td><?php echo (int) $i + 1; ?></td><td><?php echo $e( $it['description'] ); ?></td><td class="num"><?php echo $e( rtrim( rtrim( number_format( (float) $it['qty'], 2, '.', '' ), '0' ), '.' ) ); ?></td><td class="num"><?php echo $money( $it['price'] ); ?></td><td class="num"><?php echo $money( $it['amount'] ); ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php if ( ! empty( $inv['period_start'] ) ) : ?><p class="period">Membership period: <?php echo $fmt( $inv['period_start'] ); ?> – <?php echo $inv['period_end'] ? $fmt( $inv['period_end'] ) : 'no end date'; ?></p><?php endif; ?>
<table class="totals">
	<tr><td>Subtotal</td><td class="num"><?php echo $money( $inv['subtotal'] ); ?></td></tr>
	<?php if ( $inv['discount'] > 0 ) : ?><tr><td>Discount</td><td class="num">- <?php echo $money( $inv['discount'] ); ?></td></tr><?php endif; ?>
	<?php if ( $inv['tax_rate'] > 0 ) : ?><tr><td>SST <?php echo $e( rtrim( rtrim( number_format( $inv['tax_rate'], 2, '.', '' ), '0' ), '.' ) ); ?>%<?php echo ! empty( $inv['tax_inclusive'] ) ? ' (included)' : ''; ?></td><td class="num"><?php echo $money( $inv['tax'] ); ?></td></tr><?php endif; ?>
	<tr class="grand"><td>Total</td><td class="num"><?php echo $money( $inv['total'] ); ?></td></tr>
</table>

<?php if ( $paid ) : ?>
	<div class="paid"><strong>Payment received</strong> on <?php echo $fmt( $inv['paid_date'] ); ?><?php echo $inv['payment_method'] ? ' · ' . $e( $inv['payment_method'] ) : ''; ?><?php echo $inv['payment_ref'] ? ' · Ref: ' . $e( $inv['payment_ref'] ) : ''; ?></div>
<?php elseif ( ! $void && ( $s['bank_account_no'] || $s['invoice_terms'] || $qr || ( ! empty( $inv['token'] ) && NHM_Payments::available() ) ) ) : ?>
	<div class="pay"><table><tr><td>
		<?php if ( ! empty( $inv['token'] ) && NHM_Payments::available() ) : ?>
			<strong>Pay online</strong> (<?php echo $e( implode( ', ', array_map( function ( $m ) { return 'stripe' === $m ? 'card, FPX, GrabPay' : ( 'qr' === $m ? 'DuitNow QR' : 'bank transfer' ); }, array_keys( NHM_Payments::available() ) ) ) ); ?>):<br>
			<a href="<?php echo $e( NHM_Payments::pay_url( $inv ) ); ?>"><?php echo $e( NHM_Payments::pay_url( $inv ) ); ?></a><br><br>
		<?php endif; ?>
		<?php if ( $s['bank_account_no'] ) : ?>
			<strong>Payment details</strong><br>
			<?php echo self::lines( array( $s['bank_name'] ? 'Bank: ' . $s['bank_name'] : '', $s['bank_account_name'] ? 'Account name: ' . $s['bank_account_name'] : '', 'Account no.: ' . $s['bank_account_no'], 'Reference: ' . $inv['number'] ) ); ?><br>
		<?php endif; ?>
		<?php if ( $s['invoice_terms'] ) : ?><span class="small"><?php echo nl2br( $e( $s['invoice_terms'] ) ); ?></span><?php endif; ?>
	</td>
	<?php if ( $qr ) : ?>
		<td class="qr"><img src="<?php echo $e( $qr ); ?>"><br><span class="small"><strong>DuitNow QR</strong><br><span class="muted">Scan with any banking app</span></span></td>
	<?php endif; ?>
	</tr></table></div>
<?php endif; ?>

<?php if ( '' !== trim( $inv['notes'] ) ) : ?><p class="small" style="margin-top:14px"><?php echo nl2br( $e( $inv['notes'] ) ); ?></p><?php endif; ?>

<div class="foot"><?php echo $e( $s['invoice_footer'] ); ?><?php echo $s['invoice_footer'] ? ' · ' : ''; ?><?php echo $e( $biz ); ?></div>
</body></html>
		<?php
		return (string) ob_get_clean();
	}

	/** Refund Note: confirms money paid back for an invoice. */
	public static function refund_html( $r ) {
		$inv   = NHM_Invoices::get( $r['invoice_id'] );
		$s     = NHM_Settings::all();
		$brand = NHM_Settings::brand();
		$e     = array( __CLASS__, 'e' );
		$cur   = $inv ? $inv['currency'] : (string) $s['currency'];
		$money = function ( $n ) use ( $cur ) {
			return self::e( trim( $cur . ' ' . number_format( (float) $n, 2, '.', ',' ) ) );
		};
		$fmt   = function ( $d ) {
			return $d ? self::e( wp_date( 'j M Y', strtotime( substr( $d, 0, 10 ) . ' 12:00:00' ) ) ) : '';
		};
		$biz   = $s['biz_name'] ? $s['biz_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$addr  = array_filter( array_map( 'trim', explode( "\n", (string) $s['biz_address'] ) ) );
		$from  = array_merge( $addr, array( trim( $s['biz_postcode'] . ' ' . $s['biz_city'] ), trim( implode( ', ', array_filter( array( $s['biz_state'], $s['biz_country'] ) ) ) ) ) );
		$ids   = array_filter( array( $s['biz_reg'] ? 'Reg. No.: ' . $s['biz_reg'] : '', $s['biz_tin'] ? 'TIN: ' . $s['biz_tin'] : '' ) );
		$b     = $inv ? $inv['bill_to'] : array();
		$bill  = isset( $b['address'] ) ? (array) $b['address'] : array();
		$line  = $inv && ! empty( $inv['items'][0]['description'] ) ? $inv['items'][0]['description'] : '';
		$pay   = $r['payment_id'] ? NHM_Payments::get( $r['payment_id'] ) : null;
		$how   = 'stripe' === $r['method'] ? ( $pay && $pay['stripe_method'] ? NHM_Stripe::method_label( $pay['stripe_method'] ) : 'Card' ) . ' (back to the original payment method)' : 'Bank transfer';
		$logo  = self::logo_src();
		ob_start();
		?><!doctype html>
<html><head><meta charset="utf-8"><style>
@page { margin: 34px 40px 60px; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #1d2330; line-height: 1.45; }
.bar { height: 5px; background: <?php echo $e( $brand ); ?>; margin: 0 0 18px; }
table { border-collapse: collapse; width: 100%; }
.head td { vertical-align: top; }
.biz { font-size: 13pt; font-weight: bold; }
.muted { color: #6b7280; }
.small { font-size: 8.3pt; }
.title { font-size: 20pt; font-weight: bold; letter-spacing: 1px; color: <?php echo $e( $brand ); ?>; text-align: right; }
.meta td { padding: 1px 0; }
.meta td.k { color: #6b7280; padding-right: 10px; text-align: right; }
.meta td.v { text-align: right; font-weight: bold; }
.box { margin: 22px 0 16px; }
.box h3 { font-size: 8pt; letter-spacing: 1px; color: #6b7280; margin: 0 0 4px; text-transform: uppercase; }
.items th { background: #f5f6f8; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .5px; color: #4b5563; padding: 7px 8px; border-bottom: 1px solid #e5e7eb; }
.items td { padding: 8px; border-bottom: 1px solid #eef0f3; vertical-align: top; }
.num, .items th.num { text-align: right; white-space: nowrap; }
.totals { width: 46%; margin-left: 54%; margin-top: 10px; }
.totals td { padding: 4px 8px; }
.totals tr.grand td { font-size: 12pt; font-weight: bold; border-top: 2px solid #1d2330; padding-top: 7px; }
.info { margin-top: 18px; padding: 10px 12px; background: #f5f6f8; }
.foot { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 7.5pt; color: #8b909a; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 6px; }
</style></head><body>
<div class="bar"></div>
<table class="head"><tr>
	<td style="width:58%">
		<?php if ( $logo ) : ?><img src="<?php echo $e( $logo ); ?>" style="max-height:58px;max-width:200px;margin-bottom:8px"><br><?php endif; ?>
		<span class="biz"><?php echo $e( $biz ); ?></span><br>
		<?php if ( $ids ) : ?><span class="small muted"><?php echo self::lines( $ids ); ?></span><br><?php endif; ?>
		<span class="small"><?php echo self::lines( $from ); ?></span>
	</td>
	<td style="width:42%">
		<div class="title">REFUND NOTE</div>
		<table class="meta" style="margin-top:8px">
			<tr><td class="k">Refund note no.</td><td class="v"><?php echo $e( $r['number'] ); ?></td></tr>
			<tr><td class="k">Date</td><td class="v"><?php echo $fmt( $r['refund_date'] ); ?></td></tr>
			<?php if ( $inv ) : ?><tr><td class="k">Original invoice</td><td class="v"><?php echo $e( $inv['number'] ); ?></td></tr>
			<tr><td class="k">Invoice date</td><td class="v"><?php echo $fmt( $inv['issue_date'] ); ?></td></tr><?php endif; ?>
		</table>
	</td>
</tr></table>

<div class="box">
	<h3>Refunded to</h3>
	<strong><?php echo $e( isset( $b['name'] ) ? $b['name'] : '' ); ?></strong><br>
	<?php if ( $bill ) : ?><?php echo self::lines( $bill ); ?><br><?php endif; ?>
	<?php if ( ! empty( $b['email'] ) ) : ?><span class="small muted"><?php echo $e( $b['email'] ); ?></span><?php endif; ?>
</div>

<table class="items">
	<thead><tr><th style="width:5%">#</th><th>Description</th><th class="num" style="width:20%">Amount paid</th><th class="num" style="width:20%">Refunded</th></tr></thead>
	<tbody><tr><td>1</td><td>Refund for invoice <?php echo $e( $inv ? $inv['number'] : '' ); ?><?php echo $line ? '<br><span class="small muted">' . $e( $line ) . '</span>' : ''; ?></td><td class="num"><?php echo $money( $r['paid_amount'] ); ?></td><td class="num"><?php echo $money( $r['amount'] ); ?></td></tr></tbody>
</table>
<table class="totals">
	<tr class="grand"><td>Total refunded</td><td class="num"><?php echo $money( $r['amount'] ); ?></td></tr>
</table>

<div class="info">
	<strong>Refund details</strong><br>
	Method: <?php echo $e( $how ); ?><br>
	<?php if ( '' !== $r['reference'] ) : ?>Reference: <?php echo $e( $r['reference'] ); ?><br><?php endif; ?>
	<?php if ( '' !== $r['reason'] ) : ?>Reason: <?php echo $e( $r['reason'] ); ?><br><?php endif; ?>
	<?php if ( $r['amount'] + 0.005 < $r['paid_amount'] ) : ?><span class="small muted">The difference is the non-refundable payment processing fee and/or the part not refunded under our Refund Policy.</span><br><?php endif; ?>
	<span class="small muted">The membership bought with this invoice has ended.</span>
</div>

<div class="foot"><?php echo $e( str_ireplace( 'invoice', 'document', (string) $s['invoice_footer'] ) ); ?><?php echo $s['invoice_footer'] ? ' · ' : ''; ?><?php echo $e( $biz ); ?></div>
</body></html>
		<?php
		return (string) ob_get_clean();
	}
}
