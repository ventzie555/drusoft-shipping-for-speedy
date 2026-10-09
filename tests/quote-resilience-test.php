<?php
// Can one bad Speedy reply ship an order for free, and what does the customer pay vs what Speedy bills?
// Real cart, real session, real shipping method; only api.speedy.bg/v1/calculate is intercepted.
// Usage: wp eval-file wp-content/plugins/drusoft-shipping-for-speedy/tests/quote-resilience-test.php
$INSTANCE = null;
foreach ( wp_load_alloptions() as $k => $v ) {
	if ( preg_match( '/^woocommerce_drushfo_speedy_(\d+)_settings$/', $k, $m ) ) { $INSTANCE = (int) $m[1]; break; }
}
if ( ! $INSTANCE ) { echo "no drushfo_speedy instance configured\n"; return; }
if ( ! WC()->session ) { WC()->initialize_session(); }
global $wpdb;
$pid   = wc_get_product_id_by_sku( 'COD-TEST-2999' );
$sofia = $wpdb->get_row( "SELECT id, name, post_code FROM {$wpdb->prefix}drushfo_cities WHERE name = 'София' LIMIT 1" );
$office = 0;
if ( ! $pid || ! $sofia ) { echo "need COD-TEST-2999 and synced cities\n"; return; }

$all = true;
$check = function ( string $label, bool $ok, string $detail = '' ) use ( &$all ) { $all &= $ok; printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  [$detail]" : '' ); };
$captured = []; $reply = null;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured, &$reply ) {
	if ( ! str_contains( $url, 'api.speedy.bg/v1/calculate' ) ) { return $pre; }
	$captured[] = json_decode( $args['body'], true );
	if ( $reply instanceof WP_Error ) { return $reply; }
	return [ 'response' => [ 'code' => (int) ( $reply['__code'] ?? 200 ) ], 'body' => wp_json_encode( array_diff_key( $reply, [ '__code' => 1 ] ) ), 'headers' => [] ];
}, 10, 3 );
$logged = [];
add_filter( 'woocommerce_logger_log_message', function ( $message, $level, $context ) use ( &$logged ) { if ( 'drusoft-shipping-for-speedy' === ( $context['source'] ?? '' ) ) { $logged[] = $level . ': ' . $message; } return $message; }, 10, 3 );
$ok = [ 'calculations' => [ [ 'serviceId' => 505, 'price' => [ 'amount' => 3.73, 'vat' => 0.75, 'total' => 4.48, 'currency' => 'EUR' ] ] ] ];

WC()->cart->empty_cart(); WC()->cart->add_to_cart( $pid, 1 );
WC()->session->set( 'chosen_shipping_methods', [ 'drushfo_speedy:' . $INSTANCE ] );
WC()->session->set( 'chosen_payment_method', 'cod' );
$reset = function () { foreach ( [ 'drushfo_service_options', 'drushfo_shipping_cost', 'drushfo_shipping_data', 'drushfo_selected_service', 'drushfo_city_id', 'drushfo_office_id', 'drushfo_delivery_type' ] as $k ) { WC()->session->set( $k, null ); } };
$post = function ( string $type, string $postcode = '2230', int $office_id = 0 ) use ( $sofia ) {
	$_POST = [ 'post_data' => http_build_query( [ 'billing_country' => 'BG', 'billing_state' => 'BG-22', 'billing_city' => (string) $sofia->id, 'billing_postcode' => $postcode, 'speedy_delivery_type' => $type, 'speedy_office_id' => $office_id ?: '', 'payment_method' => 'cod', 'shipping_method' => [ 'drushfo_speedy:1' ] ] ), 'security' => 'x' ];
};
$method = new Drushfo_Shipping_Method( $INSTANCE );
$rate = function () use ( &$method ) { $method->rates = []; $method->calculate_shipping( WC()->cart->get_shipping_packages()[0] ); $r = reset( $method->rates ); return $r ? [ 'cost' => (float) $r->get_cost(), 'tax' => (float) $r->get_shipping_tax(), 'meta' => $r->get_meta_data() ] : null; };

// 1. Live quote: what reaches Speedy, what the rate carries.
$reply = $ok; $captured = []; $reset(); $post( 'address', '2230' );
$r = $rate();
$sent = $captured[0] ?? [];
$check( 'address quote: siteId sent, no postcode in the payload', (int) ( $sent['recipient']['addressLocation']['siteId'] ?? 0 ) === (int) $sofia->id && ! isset( $sent['recipient']['addressLocation']['postCode'] ), 'siteId=' . ( $sent['recipient']['addressLocation']['siteId'] ?? '?' ) );
$check( 'taxes off: rate cost = Speedy total 4.48', $r && 4.48 === $r['cost'], 'cost=' . var_export( $r['cost'] ?? null, true ) );
$check( 'courierServicePayer RECIPIENT at quote time on COD (Speedy prices both payers the same — measured 10.10)', 'RECIPIENT' === ( $sent['payment']['courierServicePayer'] ?? '' ), $sent['payment']['courierServicePayer'] ?? '?' );

// 2. First quote fails (Speedy refuses / silent / empty) → table price, logged, flagged; only a transport failure pauses.
delete_transient( 'drushfo_api_down' );
foreach ( [ 'refusal (HTTP 400)' => [ '__code' => 400, 'error' => [ 'message' => 'Invalid site', 'code' => 1 ] ], 'timeout' => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), '200 without calculations' => [ 'calculations' => [] ] ] as $label => $bad ) {
	delete_transient( 'drushfo_api_down' );
	$reply = $bad; $logged = []; $reset(); $post( 'address', '1000' );
	$r = $rate();
	$check( "$label on the first quote → table price 4.30 (address default), never 0.00", $r && 4.30 === $r['cost'], 'cost=' . var_export( $r['cost'] ?? null, true ) );
	$check( "$label → logged", (bool) preg_grep( '/calculate failed/', $logged ), $logged ? mb_substr( $logged[0], 0, 90 ) : 'nothing logged' );
	$check( "$label → rate flagged priced_by=fallback with the reason", 'fallback' === ( $r['meta']['priced_by'] ?? '' ) && '' !== ( $r['meta']['fallback_reason'] ?? '' ), wp_json_encode( $r['meta'] ?? [], JSON_UNESCAPED_UNICODE ) );
	$check( "$label → pause " . ( 'timeout' === $label ? 'set' : 'NOT set' ), ( 'timeout' === $label ) === (bool) get_transient( 'drushfo_api_down' ) );
}
// paused: no call to Speedy; after the pause Speedy is asked again
$reply = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ); $reset(); $post( 'address', '1000' ); $rate();
$reply = $ok; $captured = []; $reset(); $post( 'address', '1000' ); $r = $rate();
$check( 'while paused: no call, table price', 0 === count( $captured ) && 'fallback' === ( $r['meta']['priced_by'] ?? '' ), 'calls=' . count( $captured ) );
delete_transient( 'drushfo_api_down' ); $captured = []; $reset(); $post( 'address', '1000' ); $r = $rate();
$check( 'after the pause: Speedy asked, live 4.48', 1 === count( $captured ) && 4.48 === $r['cost'] && 'api' === ( $r['meta']['priced_by'] ?? '' ), 'calls=' . count( $captured ) . ' cost=' . var_export( $r['cost'] ?? null, true ) );
// office/automat table prices, cleared field, deliberate 0
$opt = 'woocommerce_drushfo_speedy_' . $INSTANCE . '_settings'; $saved_opt = get_option( $opt );
$reply = [ 'calculations' => [] ];
$reset(); $post( 'automat', '1000', 9999 ); $r = $rate(); $check( 'automat fallback 2.50', $r && 2.50 === $r['cost'], 'cost=' . var_export( $r['cost'] ?? null, true ) );
update_option( $opt, array_merge( $saved_opt, [ 'fallback_address' => '' ] ) ); wp_cache_flush(); $method = new Drushfo_Shipping_Method( $INSTANCE );
$reset(); $post( 'address', '1000' ); $r = $rate(); $check( 'cleared fallback field → default 4.30', $r && 4.30 === $r['cost'], 'cost=' . var_export( $r['cost'] ?? null, true ) );
update_option( $opt, array_merge( $saved_opt, [ 'fallback_address' => '0' ] ) ); wp_cache_flush(); $method = new Drushfo_Shipping_Method( $INSTANCE );
$reset(); $post( 'address', '1000' ); $r = $rate(); $check( 'a deliberate 0 stays 0', $r && 0.0 === $r['cost'], 'cost=' . var_export( $r['cost'] ?? null, true ) );
update_option( $opt, $saved_opt ); wp_cache_flush(); $method = new Drushfo_Shipping_Method( $INSTANCE );

// 3. Quote fails AFTER a good one → the last price is reused (stale but not free).
$reply = $ok; $reset(); $post( 'address', '1000' ); $rate();
$reply = new WP_Error( 'http_request_failed', 'cURL error 7' ); $post( 'address', '1000' );
$r = $rate();
$check( 'failure after a good quote → last price 4.48 reused', $r && 4.48 === $r['cost'], 'cost=' . var_export( $r['cost'] ?? null, true ) );

// 4. Office quote with a real office id.
if ( $office ) {
	$reply = $ok; $captured = []; $reset(); $post( 'office', '1000', $office );
	$r = $rate();
	$check( 'office quote priced, pickupOfficeId sent', $r && 4.48 === $r['cost'] && (int) ( $captured[0]['recipient']['pickupOfficeId'] ?? 0 ) === $office, 'cost=' . var_export( $r['cost'] ?? null, true ) );
}

// 4b. Shipping tax on → the customer sees Speedy's total, not total + 20 %.
$tax_backup = [ get_option( 'woocommerce_calc_taxes' ), get_option( 'woocommerce_shipping_tax_class' ) ];
update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_shipping_tax_class', 'inherit' );
$rate_id = WC_Tax::_insert_tax_rate( [ 'tax_rate_country' => 'BG', 'tax_rate' => '20.0000', 'tax_rate_name' => 'ДДС тест', 'tax_rate_priority' => 1, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ] );
WC_Cache_Helper::invalidate_cache_group( 'taxes' ); wp_cache_flush(); WC()->customer->set_billing_country( 'BG' ); WC()->customer->set_shipping_country( 'BG' );
$reply = $ok; $reset(); $post( 'address', '1000' ); $r = $rate();
$gross = $r ? round( $r['cost'] + $r['tax'], 2 ) : -1;
$check( 'taxes on: rate cost + tax = Speedy total 4.48 (net ' . ( $r['cost'] ?? '?' ) . ' + tax ' . ( $r ? round( $r['tax'], 2 ) : '?' ) . ')', 4.48 === $gross, 'gross=' . $gross );
WC_Tax::_delete_tax_rate( $rate_id ); update_option( 'woocommerce_calc_taxes', $tax_backup[0] ); update_option( 'woocommerce_shipping_tax_class', $tax_backup[1] ); WC_Cache_Helper::invalidate_cache_group( 'taxes' ); wp_cache_flush();

// 5. Order saved with the typed 2230: normalised from the cities table.
$o = wc_create_order(); $o->add_product( wc_get_product( $pid ), 1 );
$o->set_billing_city( (string) $sofia->id ); $o->set_billing_postcode( '2230' ); $o->set_billing_state( 'BG-22' ); $o->set_billing_country( 'BG' );
$o->set_shipping_city( (string) $sofia->id ); $o->set_shipping_postcode( '2230' ); $o->set_shipping_state( 'BG-22' ); $o->set_shipping_country( 'BG' );
$i = new WC_Order_Item_Shipping(); $i->set_method_id( 'drushfo_speedy' ); $i->set_instance_id( $INSTANCE ); $i->set_total( 4.48 ); $o->add_item( $i );
$o->set_payment_method( 'cod' ); $o->calculate_totals( false ); $o->save();
if ( function_exists( 'drushfo_order_city_from_id' ) ) { drushfo_order_city_from_id( $o ); } else { do_action( 'woocommerce_checkout_create_order', $o, [] ); }
$o->save(); $o = wc_get_order( $o->get_id() );
$check( 'order: postcode normalised to ' . $sofia->post_code . ', city name stored', $sofia->post_code === $o->get_billing_postcode() && $sofia->name === $o->get_billing_city(), 'pc=' . $o->get_billing_postcode() . ' city=' . $o->get_billing_city() );
$o->delete( true );

WC()->cart->empty_cart(); unset( $_POST ); $reset();
echo $all ? "ALL PASS\n" : "SOME FAILED\n";
