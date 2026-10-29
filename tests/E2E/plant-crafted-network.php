<?php
/**
 * Run with wp eval-file by NetworkSecurityE2ETest: hostile data a crafted archive could carry.
 *
 * @package LightweightPlugins\Migration
 */

global $wpdb;
$e   = (int) get_user_by( 'login', 'editor2' )->ID;
$app = [ [ 'uuid' => 'planted', 'app_id' => '', 'name' => 'planted', 'password' => wp_hash_password( 'planted' ), 'created' => 1, 'last_used' => null, 'last_ip' => null ] ];
update_user_meta( $e, $wpdb->base_prefix . 'capabilities', [ 'administrator' => true ] );
update_user_meta( $e, $wpdb->base_prefix . 'user_level', 10 );
update_user_meta( $e, $wpdb->base_prefix . '2_capabilities', [ 'administrator' => true, 'manage_network_options' => true ] );
update_user_meta( 1, '_application_passwords', $app );
update_user_meta( $e, '_application_passwords', $app );
update_site_option( 'site_admins', [ 'admin', 'editor2' ] );
$key   = $wpdb->base_prefix . '2_user_roles';
$roles = get_blog_option( 2, $key );
$roles['manage_network_users'] = [ 'name' => 'Net', 'capabilities' => [ 'read' => true, 'manage_network_users' => true ] ];
$roles['evil role!']           = [ 'name' => 'Odd', 'capabilities' => [ 'read' => true ] ];
$roles['editor']['capabilities']['manage_network_users']   = true;
$roles['editor']['capabilities']['manage_network_plugins'] = true;
update_blog_option( 2, $key, $roles );
