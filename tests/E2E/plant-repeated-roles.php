<?php
/**
 * Run with wp eval-file by NetworkSecurityE2ETest: hostile data a crafted archive could carry.
 *
 * @package LightweightPlugins\Migration
 */

global $wpdb;
$table = $wpdb->base_prefix . '2_options';
$key   = $wpdb->base_prefix . '2_user_roles';
$evil  = get_blog_option( 2, $key );
$evil['editor']['capabilities']['manage_network_users'] = true;
$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX option_name" );
foreach ( [ strtoupper( $key ), $key . "\u{A0}", $key . "\u{3000}" ] as $name ) {
	$wpdb->insert( $table, [ 'option_name' => $name, 'option_value' => serialize( $evil ), 'autoload' => 'on' ] );
}
echo $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE option_name = %s", $key ) ), "\n";
