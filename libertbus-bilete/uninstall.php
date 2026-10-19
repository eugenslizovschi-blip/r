<?php
/**
 * Șterge datele doar dacă administratorul a cerut asta în Setări.
 *
 * @package LibertBus_Bilete
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lbb_settings = get_option( 'lbb_settings', array() );
if ( empty( $lbb_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lbb_bookings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lbb_routes" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
// Datele temporare (ex. ruta respinsă la salvare, ținută 5 minute ca formularul să nu revină gol) rămân în
// wp_options până expiră și le curăță WordPress; le ștergem odată cu restul.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_lbb\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lbb\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( array( 'lbb_settings', 'lbb_db_version', 'lbb_legal_pages', 'lbb_needs_product', 'lbb_preview_token', 'lbb_mail_failed', 'lbb_mail_test', 'lbb_last_cleanup', 'lbb_ticket_key' ) as $lbb_option ) {
	delete_option( $lbb_option );
}
$lbb_product = (int) get_option( 'lbb_ticket_product_id' );
if ( $lbb_product ) {
	wp_delete_post( $lbb_product, true );
}
delete_option( 'lbb_ticket_product_id' );
