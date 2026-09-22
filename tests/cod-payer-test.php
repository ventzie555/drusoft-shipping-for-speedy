<?php
/**
 * Is the si-brand.eu COD double-charge possible under Speedy?
 * Real generator, real WC orders; only the HTTP layer is intercepted, so no
 * shipment reaches Speedy. Usage: wp eval-file tests/speedy-cod-payer-test.php
 */
$INSTANCE = null;
foreach ( wp_load_alloptions() as $k => $v ) {
	if ( preg_match( '/^woocommerce_drushfo_speedy_(\\d+)_settings$/', $k, $m ) ) { $INSTANCE = (int) $m[1]; break; }
}
if ( ! $INSTANCE ) { echo "no drushfo_speedy instance configured on this site\n"; return; }
$captured = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
	if ( false === strpos( $url, 'api.speedy.bg' ) ) { return $pre; }
	$captured[] = json_decode( $args['body'] ?? '', true );
	return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [
		'id' => '63700000001', 'parcels' => [ [ 'id' => '63700000001' ] ],
		'price' => [ 'total' => 4.48, 'currency' => 'EUR' ], 'pdfURL' => '' ] ) ];
}, 10, 3 );

$pid = wc_get_product_id_by_sku( 'COD-TEST-2999' );
if ( ! $pid ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'COD test item' ); $p->set_sku( 'COD-TEST-2999' ); $p->set_regular_price( '29.99' ); $p->set_weight( '0.5' );
	$pid = $p->save();
}

/** Payload as calculate_shipping() would have saved it at checkout. */
function quote_payload( string $payer, bool $include_shipping, bool $cod, float $items_ex_vat = 29.99 ): array {
	$p = [
		'service'   => [ 'serviceIds' => [ 505 ], 'additionalServices' => [] ],
		'payment'   => [ 'courierServicePayer' => $payer ],
		'recipient' => [ 'privatePerson' => true, 'pickupOfficeId' => 1180 ],
		'content'   => [ 'parcelsCount' => 1, 'totalWeight' => 0.5, 'package' => 'BOX' ],
		'sender'    => [ 'dropoffOfficeId' => 483 ],
	];
	if ( $cod ) {
		$p['service']['additionalServices']['cod'] = array_filter( [
			'amount'                => $items_ex_vat,
			'processingType'        => 'CASH',
			'ignoreIfNotApplicable' => true,
			'includeShippingPrice'  => $include_shipping ? true : null,
		], fn( $v ) => null !== $v );
	}
	return $p;
}

function mk_order( int $pid, int $instance, string $payment, float $shipping, array $payload, int $qty = 1 ): WC_Order {
	$o = wc_create_order();
	$o->add_product( wc_get_product( $pid ), $qty );
	$i = new WC_Order_Item_Shipping();
	$i->set_method_title( 'Доставка със Спиди' ); $i->set_method_id( 'drushfo_speedy' );
	$i->set_instance_id( $instance ); $i->set_total( (string) $shipping );
	$o->add_item( $i );
	$o->set_payment_method( $payment );
	$o->set_address( [ 'first_name' => 'Тест', 'last_name' => 'Клиент', 'phone' => '0888123456', 'email' => 'test@example.com', 'city' => 'София', 'postcode' => '1000', 'country' => 'BG', 'address_1' => 'ул. Тестова 1' ], 'billing' );
	$o->set_address( [ 'first_name' => 'Тест', 'last_name' => 'Клиент', 'city' => 'София', 'postcode' => '1000', 'country' => 'BG', 'address_1' => 'ул. Тестова 1' ], 'shipping' );
	$o->update_meta_data( '_drushfo_order_data', $payload );
	$o->calculate_totals( false );
	$o->set_status( 'processing' );
	$o->save();
	return $o;
}

function run( string $name, array $cfg, array &$captured, int $pid, int $instance, array $expect ): bool {
	$captured = [];
	$o   = mk_order( $pid, $instance, $cfg['payment'] ?? 'cod', $cfg['shipping'], $cfg['payload'], $cfg['qty'] ?? 1 );
	$res = Drushfo_Waybill_Generator::instance()->generate_waybill( $o->get_id() );
	$sent  = $captured[0] ?? [];
	$payer = $sent['payment']['courierServicePayer'] ?? '(none)';
	$cod   = $sent['service']['additionalServices']['cod'] ?? null;
	$amt   = null === $cod ? null : (float) ( $cod['amount'] ?? 0 );
	$incl  = null !== $cod && ! empty( $cod['includeShippingPrice'] );
	$total = (float) $o->get_total();

	// What the customer ends up paying: COD collected + Speedy's fee when the
	// recipient is the payer. 4.48 is the fee the stub returns.
	$customer_pays = ( null === $amt ? 0.0 : $amt ) + ( 'RECIPIENT' === $payer ? 4.48 : 0.0 );

	$ok = true; $why = [];
	if ( is_wp_error( $res ) ) { $ok = false; $why[] = 'generator error: ' . $res->get_error_message(); }
	if ( $payer !== $expect['payer'] ) { $ok = false; $why[] = "payer $payer, expected {$expect['payer']}"; }
	if ( array_key_exists( 'cod', $expect ) ) {
		if ( null === $expect['cod'] && null !== $amt ) { $ok = false; $why[] = "unexpected COD $amt"; }
		if ( null !== $expect['cod'] && ( null === $amt || abs( $amt - $expect['cod'] ) > 0.005 ) ) { $ok = false; $why[] = 'COD ' . var_export( $amt, true ) . ", expected {$expect['cod']}"; }
	}
	if ( isset( $expect['includes'] ) && $incl !== $expect['includes'] ) { $ok = false; $why[] = 'includeShippingPrice ' . var_export( $incl, true ); }
	// the whole point: never more than the order total, unless the customer was
	// told shipping was free and the courier is the one billing them.
	if ( isset( $expect['customer_pays'] ) && abs( $customer_pays - $expect['customer_pays'] ) > 0.005 ) {
		$ok = false; $why[] = sprintf( 'customer pays %.2f, expected %.2f', $customer_pays, $expect['customer_pays'] );
	}
	printf( "%s  %-56s total %6.2f  payer %-9s COD %-7s → customer pays %.2f\n",
		$ok ? 'PASS' : 'FAIL', $name, $total, $payer, null === $amt ? 'none' : number_format( $amt, 2 ), $customer_pays );
	foreach ( $why as $w ) { echo "        ! $w\n"; }
	$o->delete( true );
	return $ok;
}

$all = true;
// The si-brand shape: customer charged shipping at checkout AND courier billing them.
$all &= run( 'si-brand shape: shipping charged + quote said RECIPIENT',
	[ 'shipping' => 5.11, 'payload' => quote_payload( 'RECIPIENT', false, true ) ], $captured, $pid, $INSTANCE,
	[ 'payer' => 'SENDER', 'cod' => 35.10, 'includes' => false, 'customer_pays' => 35.10 ] );
$all &= run( 'shipping charged + quote said SENDER',
	[ 'shipping' => 5.11, 'payload' => quote_payload( 'SENDER', false, true ) ], $captured, $pid, $INSTANCE,
	[ 'payer' => 'SENDER', 'cod' => 35.10, 'includes' => false, 'customer_pays' => 35.10 ] );
$all &= run( 'free shipping + RECIPIENT (courier bills the customer)',
	[ 'shipping' => 0, 'payload' => quote_payload( 'RECIPIENT', false, true ) ], $captured, $pid, $INSTANCE,
	[ 'payer' => 'RECIPIENT', 'cod' => 29.99, 'customer_pays' => 34.47 ] );
$all &= run( 'includeShippingPrice + shipping charged (must be dropped)',
	[ 'shipping' => 5.11, 'payload' => quote_payload( 'RECIPIENT', true, true ) ], $captured, $pid, $INSTANCE,
	[ 'payer' => 'SENDER', 'cod' => 35.10, 'includes' => false, 'customer_pays' => 35.10 ] );
$all &= run( 'COD order whose quote carried no COD service at all',
	[ 'shipping' => 5.11, 'payload' => quote_payload( 'SENDER', false, false ) ], $captured, $pid, $INSTANCE,
	[ 'payer' => 'SENDER', 'cod' => 35.10, 'includes' => false, 'customer_pays' => 35.10 ] );
$all &= run( 'card payment: no COD, shop pays courier',
	[ 'payment' => 'revolut_cc', 'shipping' => 5.11, 'payload' => quote_payload( 'SENDER', false, false ) ], $captured, $pid, $INSTANCE,
	[ 'payer' => 'SENDER', 'cod' => null, 'customer_pays' => 0.0 ] );
$all &= run( '3 units, shipping charged + RECIPIENT quote',
	[ 'qty' => 3, 'shipping' => 5.11, 'payload' => quote_payload( 'RECIPIENT', false, true, 89.97 ) ], $captured, $pid, $INSTANCE,
	[ 'payer' => 'SENDER', 'cod' => 95.08, 'includes' => false, 'customer_pays' => 95.08 ] );
echo $all ? "\nALL PASS — Speedy cannot reproduce the Econt double charge\n" : "\nSOME FAILED\n";
