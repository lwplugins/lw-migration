<?php
/**
 * Run with wp eval-file by NetworkSecurityE2ETest: hostile data a crafted archive could carry.
 *
 * @package LightweightPlugins\Migration
 */

global $wpdb;
$id = wp_insert_user( [ 'user_login' => 'evil1', 'user_pass' => 'evil-pass', 'user_email' => 'evil1@site-a.test', 'role' => 'editor' ] );
update_user_meta( $id, $wpdb->prefix . 'capabilities', [ 'administrator' => true, 'manage_network_options' => true ] );
$key   = $wpdb->prefix . 'user_roles';
$roles = get_option( $key );
$roles['manage_network_users'] = [ 'name' => 'Net', 'capabilities' => [ 'read' => true, 'manage_network_users' => true ] ];
$roles['evil role!']           = [ 'name' => 'Odd', 'capabilities' => [ 'read' => true ] ];
$roles['editor']['capabilities']['manage_network_users']   = true;
$roles['editor']['capabilities']['manage_network_plugins'] = true;
update_option( $key, $roles );
