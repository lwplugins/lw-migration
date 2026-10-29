<?php
/**
 * The user-id columns a restored site's tables are renumbered in.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Decision 19 (reference rule R9): table suffix after a site's prefix ⇒ the
 * column holding a user id, as the established .wpress tooling renumbers them,
 * plus the two order columns (open user decision U3). Post meta (_edit_last,
 * _customer_user) and other plugins' tables are not renumbered. Pure.
 */
final class RenumberColumns {

	/** @var array<int, array{0: string, 1: string}> */
	public const LIST = [
		[ 'comments', 'user_id' ],
		[ 'posts', 'post_author' ],
		[ 'automatewoo_abandoned_carts', 'user_id' ],
		[ 'automatewoo_customers', 'user_id' ],
		[ 'gf_entry_notes', 'user_id' ],
		[ 'mailpoet_subscribers', 'wp_user_id' ],
		[ 'mailpoet_user_flags', 'user_id' ],
		[ 'wc_crm_customer_list', 'user_id' ],
		[ 'wc_crm_log', 'user_id' ],
		[ 'wc_download_log', 'user_id' ],
		[ 'wc_webhooks', 'user_id' ],
		[ 'woocommerce_api_keys', 'user_id' ],
		[ 'woocommerce_downloadable_product_permissions', 'user_id' ],
		[ 'woocommerce_payment_tokens', 'user_id' ],
		[ 'wc_orders', 'customer_id' ],
		[ 'wc_customer_lookup', 'user_id' ],
		[ 'bp_activity', 'user_id' ],
		[ 'bp_document', 'user_id' ],
		[ 'bp_document_folder', 'user_id' ],
		[ 'bp_follow', 'leader_id' ],
		[ 'bp_follow', 'follower_id' ],
		[ 'bp_friends', 'initiator_user_id' ],
		[ 'bp_friends', 'friend_user_id' ],
		[ 'bp_groups', 'creator_id' ],
		[ 'bp_groups_members', 'user_id' ],
		[ 'bp_groups_members', 'inviter_id' ],
		[ 'bp_invitations', 'user_id' ],
		[ 'bp_invitations', 'inviter_id' ],
		[ 'bp_media', 'user_id' ],
		[ 'bp_media_albums', 'user_id' ],
		[ 'bp_messages_messages', 'sender_id' ],
		[ 'bp_messages_recipients', 'user_id' ],
		[ 'bp_notifications', 'user_id' ],
		[ 'bp_user_blogs', 'user_id' ],
		[ 'bp_xprofile_data', 'user_id' ],
		[ 'bp_zoom_meetings', 'user_id' ],
	];

	/**
	 * Whether a column of this type holds an id plus the renumbering offset
	 * (2^30 + an id below it fits even a signed INT).
	 *
	 * @param string $columnType COLUMN_TYPE from information_schema (e.g. "bigint(20) unsigned").
	 */
	public static function fits( string $columnType ): bool {
		return 1 === preg_match( '/^(int|bigint)(\(\d+\))?(\s+unsigned)?(\s+zerofill)?\z/i', trim( $columnType ) );
	}
}
