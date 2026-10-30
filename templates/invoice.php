<?php
/**
 * Printable invoice / credit note (A4).
 *
 * Override: {theme}/wahou-commerce/invoice.php
 *
 * @var \WahouCommerce\Orders\Order          $order
 * @var array<string, mixed>                 $document  type (invoice|credit_note|proforma), number, date, amount, reason.
 * @var string                               $currency
 * @var string                               $logo
 * @var array<string, string>                $seller    name, store, address, country, vat, extra, email.
 * @var array<string, mixed>                 $buyer     name, company, lines[], vat, email.
 * @var array<int, array<string, mixed>>     $lines
 * @var array<string, mixed>|null            $shipping
 * @var array<int, array<string, mixed>>     $tax_lines
 * @var array<string, int>                   $totals    net, tax, gross.
 * @var string                               $mention
 * @var array<int, array<string, mixed>>     $refunds
 * @var bool                                 $paid
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Emails;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

$type        = (string) $document['type'];
$is_credit   = 'credit_note' === $type;
$date_format = get_option( 'date_format' );
$fmt         = static fn( int $amount ): string => Money::format( $amount, $currency );
$titles      = array(
	'invoice'     => __( 'Invoice', 'wahou-commerce' ),
	'credit_note' => __( 'Credit note', 'wahou-commerce' ),
	'proforma'    => __( 'Order summary (pro forma – not an invoice)', 'wahou-commerce' ),
);
$title       = $titles[ $type ] ?? $titles['invoice'];
$has_discount = (bool) array_filter( $lines, static fn( $l ) => (int) $l['discount'] > 0 );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ( '' !== (string) $document['number'] ? ' ' . $document['number'] : '' ) . ' – ' . $seller['store'] ); ?></title>
<style>
	*, *::before, *::after { box-sizing: border-box; }
	body { margin: 0; background: #eef0f3; color: #1f2328; font: 13px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
	.sheet { width: 210mm; min-height: 297mm; margin: 24px auto; padding: 18mm 16mm; background: #fff; box-shadow: 0 6px 24px rgba(0,0,0,.08); }
	.toolbar { max-width: 210mm; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
	.toolbar button { font: inherit; font-weight: 700; padding: 10px 18px; border: 0; border-radius: 8px; background: #111; color: #fff; cursor: pointer; }
	header { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; }
	header img { max-height: 56px; max-width: 200px; }
	h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -.2px; }
	.muted { color: #6a737d; }
	.parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 28px 0 20px; }
	.box { border: 1px solid #e6e8eb; border-radius: 8px; padding: 12px 14px; }
	.box h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #6a737d; margin: 0 0 6px; }
	table { width: 100%; border-collapse: collapse; }
	th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #6a737d; border-bottom: 2px solid #1f2328; padding: 8px 6px; }
	td { padding: 8px 6px; border-bottom: 1px solid #eceef1; vertical-align: top; }
	.num { text-align: right; white-space: nowrap; }
	.totals { width: 58%; margin-left: auto; margin-top: 14px; }
	.totals td { border: 0; padding: 4px 6px; }
	.totals .grand td { border-top: 2px solid #1f2328; font-size: 15px; font-weight: 700; padding-top: 8px; }
	.note { margin-top: 18px; padding: 10px 12px; background: #f6f7f9; border-radius: 8px; }
	footer { margin-top: 28px; font-size: 11px; color: #6a737d; border-top: 1px solid #eceef1; padding-top: 10px; }
	@media print {
		body { background: #fff; }
		.sheet { margin: 0; box-shadow: none; width: auto; min-height: 0; padding: 0; }
		.toolbar { display: none; }
		@page { size: A4; margin: 14mm; }
	}
	@media (max-width: 820px) {
		.sheet { width: auto; margin: 12px; padding: 20px; min-height: 0; }
		.parties { grid-template-columns: 1fr; }
		.totals { width: 100%; }
	}
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'wahou-commerce' ); ?></button></div>
<main class="sheet">
	<header>
		<div>
			<?php if ( '' !== $logo ) : ?>
				<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $seller['store'] ); ?>">
			<?php else : ?>
				<strong style="font-size:18px;"><?php echo esc_html( $seller['store'] ); ?></strong>
			<?php endif; ?>
		</div>
		<div style="text-align:right;">
			<h1><?php echo esc_html( $title ); ?></h1>
			<?php if ( '' !== (string) $document['number'] ) : ?>
				<div><strong><?php esc_html_e( 'No.', 'wahou-commerce' ); ?> <?php echo esc_html( (string) $document['number'] ); ?></strong></div>
			<?php endif; ?>
			<div class="muted">
				<?php
				/* translators: %s: date. */
				echo esc_html( sprintf( __( 'Date: %s', 'wahou-commerce' ), Emails::date( (string) $document['date'], $date_format ) ) );
				?>
			</div>
			<div class="muted">
				<?php
				/* translators: 1: order number, 2: order date. */
				echo esc_html( sprintf( __( 'Order #%1$s of %2$s', 'wahou-commerce' ), $order->number(), Emails::date( $order->created_at(), $date_format ) ) );
				?>
			</div>
			<?php if ( $is_credit && '' !== $order->invoice_number() ) : ?>
				<div class="muted">
					<?php
					/* translators: %s: invoice number. */
					echo esc_html( sprintf( __( 'Refers to invoice %s', 'wahou-commerce' ), $order->invoice_number() ) );
					?>
				</div>
			<?php endif; ?>
		</div>
	</header>

	<section class="parties">
		<div class="box">
			<h2><?php esc_html_e( 'Seller', 'wahou-commerce' ); ?></h2>
			<strong><?php echo esc_html( $seller['name'] ); ?></strong><br>
			<?php if ( '' !== $seller['address'] ) : ?>
				<?php echo nl2br( esc_html( $seller['address'] ) ); ?><br>
			<?php endif; ?>
			<?php echo esc_html( $seller['country'] ); ?><br>
			<?php if ( '' !== $seller['vat'] ) : ?>
				<?php
				/* translators: %s: VAT number. */
				echo esc_html( sprintf( __( 'VAT number: %s', 'wahou-commerce' ), $seller['vat'] ) );
				?>
				<br>
			<?php endif; ?>
			<?php if ( '' !== $seller['extra'] ) : ?>
				<?php echo nl2br( esc_html( $seller['extra'] ) ); ?><br>
			<?php endif; ?>
			<span class="muted"><?php echo esc_html( $seller['email'] ); ?></span>
		</div>
		<div class="box">
			<h2><?php esc_html_e( 'Customer', 'wahou-commerce' ); ?></h2>
			<?php if ( '' !== $buyer['company'] ) : ?>
				<strong><?php echo esc_html( $buyer['company'] ); ?></strong><br>
			<?php endif; ?>
			<?php if ( '' !== $buyer['name'] ) : ?>
				<?php echo '' !== $buyer['company'] ? esc_html( $buyer['name'] ) : '<strong>' . esc_html( $buyer['name'] ) . '</strong>'; ?><br>
			<?php endif; ?>
			<?php foreach ( $buyer['lines'] as $buyer_line ) : ?>
				<?php echo esc_html( $buyer_line ); ?><br>
			<?php endforeach; ?>
			<?php if ( '' !== $buyer['vat'] ) : ?>
				<?php
				/* translators: %s: VAT number. */
				echo esc_html( sprintf( __( 'VAT number: %s', 'wahou-commerce' ), $buyer['vat'] ) );
				?>
				<br>
			<?php endif; ?>
			<span class="muted"><?php echo esc_html( $buyer['email'] ); ?></span>
		</div>
	</section>

	<?php if ( $is_credit ) : ?>
		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Description', 'wahou-commerce' ); ?></th>
					<th class="num"><?php esc_html_e( 'VAT', 'wahou-commerce' ); ?></th>
					<th class="num"><?php esc_html_e( 'Amount excl. VAT', 'wahou-commerce' ); ?></th>
					<th class="num"><?php esc_html_e( 'VAT amount', 'wahou-commerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tax_lines as $tax_line ) : ?>
					<tr>
						<td>
							<?php
							/* translators: %s: order number. */
							echo esc_html( sprintf( __( 'Refund on order #%s', 'wahou-commerce' ), $order->number() ) );
							?>
							<?php if ( '' !== (string) ( $document['reason'] ?? '' ) ) : ?>
								<br><span class="muted"><?php echo esc_html( (string) $document['reason'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( Emails::percent( (float) $tax_line['rate'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $fmt( (int) $tax_line['net'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $fmt( (int) $tax_line['tax'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Description', 'wahou-commerce' ); ?></th>
					<th class="num"><?php esc_html_e( 'Qty', 'wahou-commerce' ); ?></th>
					<th class="num"><?php esc_html_e( 'Unit price excl. VAT', 'wahou-commerce' ); ?></th>
					<?php if ( $has_discount ) : ?>
						<th class="num"><?php esc_html_e( 'Discount excl. VAT', 'wahou-commerce' ); ?></th>
					<?php endif; ?>
					<th class="num"><?php esc_html_e( 'VAT', 'wahou-commerce' ); ?></th>
					<th class="num"><?php esc_html_e( 'Total excl. VAT', 'wahou-commerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lines as $line ) : ?>
					<tr>
						<td>
							<?php echo esc_html( (string) $line['name'] ); ?>
							<?php if ( '' !== (string) $line['detail'] ) : ?>
								<br><span class="muted"><?php echo esc_html( (string) $line['detail'] ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== (string) $line['sku'] ) : ?>
								<br><span class="muted"><?php echo esc_html( (string) $line['sku'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( (string) (int) $line['qty'] ); ?></td>
						<td class="num"><?php echo esc_html( $fmt( (int) $line['unit_net'] ) ); ?></td>
						<?php if ( $has_discount ) : ?>
							<td class="num"><?php echo (int) $line['discount'] > 0 ? '−' . esc_html( $fmt( (int) $line['discount'] ) ) : '—'; ?></td>
						<?php endif; ?>
						<td class="num"><?php echo esc_html( Emails::percent( (float) $line['rate'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $fmt( (int) $line['net'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( $shipping ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $shipping['name'] ); ?></td>
						<td class="num">1</td>
						<td class="num"><?php echo esc_html( $fmt( (int) $shipping['net'] ) ); ?></td>
						<?php if ( $has_discount ) : ?>
							<td class="num">—</td>
						<?php endif; ?>
						<td class="num"><?php echo esc_html( Emails::percent( (float) $shipping['rate'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $fmt( (int) $shipping['net'] ) ); ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<table class="totals">
		<tr>
			<td><?php esc_html_e( 'Total excl. VAT', 'wahou-commerce' ); ?></td>
			<td class="num"><?php echo esc_html( $fmt( (int) $totals['net'] ) ); ?></td>
		</tr>
		<?php foreach ( $tax_lines as $tax_line ) : ?>
			<tr>
				<td>
					<?php
					/* translators: 1: VAT rate, 2: taxable amount. */
					echo esc_html( sprintf( __( 'VAT %1$s on %2$s', 'wahou-commerce' ), Emails::percent( (float) $tax_line['rate'] ), $fmt( (int) $tax_line['net'] ) ) );
					?>
				</td>
				<td class="num"><?php echo esc_html( $fmt( (int) $tax_line['tax'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		<tr class="grand">
			<td><?php echo esc_html( $is_credit ? __( 'Total refunded incl. VAT', 'wahou-commerce' ) : __( 'Total incl. VAT', 'wahou-commerce' ) ); ?></td>
			<td class="num"><?php echo esc_html( $fmt( (int) $totals['gross'] ) ); ?></td>
		</tr>
	</table>

	<div class="note">
		<?php if ( '' !== $mention ) : ?>
			<strong><?php echo esc_html( $mention ); ?></strong><br>
		<?php endif; ?>
		<?php if ( ! $is_credit ) : ?>
			<?php
			if ( $paid && '' !== $order->paid_at() ) {
				/* translators: 1: payment date, 2: payment method. */
				echo esc_html( sprintf( __( 'Paid on %1$s by %2$s.', 'wahou-commerce' ), Emails::date( $order->paid_at(), $date_format ), $order->payment_method_title() ) );
			} else {
				/* translators: %s: amount. */
				echo esc_html( sprintf( __( 'Amount due: %s', 'wahou-commerce' ), $fmt( $order->total() ) ) );
			}
			?>
		<?php else : ?>
			<?php
			/* translators: %s: payment method. */
			echo esc_html( sprintf( __( 'Refunded to the original payment method (%s).', 'wahou-commerce' ), $order->payment_method_title() ) );
			?>
		<?php endif; ?>
	</div>

	<?php if ( $refunds ) : ?>
		<h2 style="font-size:13px;margin:22px 0 6px;"><?php esc_html_e( 'Credit notes', 'wahou-commerce' ); ?></h2>
		<table>
			<?php foreach ( $refunds as $refund ) : ?>
				<tr>
					<td>
						<?php if ( '' !== $refund['url'] ) : ?>
							<a href="<?php echo esc_url( $refund['url'] ); ?>"><?php echo esc_html( $refund['credit_note'] ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Refund', 'wahou-commerce' ); ?>
						<?php endif; ?>
						<span class="muted"> – <?php echo esc_html( Emails::date( (string) $refund['date'], $date_format ) ); ?></span>
					</td>
					<td class="num">−<?php echo esc_html( $fmt( (int) $refund['amount'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
	<?php endif; ?>

	<footer>
		<?php echo esc_html( $seller['name'] ); ?>
		<?php if ( '' !== $seller['vat'] ) : ?>
			· <?php echo esc_html( $seller['vat'] ); ?>
		<?php endif; ?>
		<?php if ( '' !== $seller['extra'] ) : ?>
			· <?php echo esc_html( preg_replace( '/\s+/', ' ', $seller['extra'] ) ); ?>
		<?php endif; ?>
	</footer>
</main>
</body>
</html>
