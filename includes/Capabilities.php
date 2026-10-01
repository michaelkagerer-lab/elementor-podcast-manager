<?php
/**
 * Centralized capability mapping.
 *
 * All permission checks in the plugin go through this class so capability
 * choices stay in one place.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {

	/** Whether media is readable directly or belongs to an editable episode. */
	public static function can_use_attachment( int $attachment_id ): bool {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return false;
		}
		if ( current_user_can( 'read_post', $attachment_id ) ) {
			return true;
		}
		$parent = (int) $attachment->post_parent;
		return $parent > 0 && EpisodePostType::CPT === get_post_type( $parent ) && current_user_can( 'edit_post', $parent );
	}

	/**
	 * Capability required to manage podcast settings and design.
	 *
	 * @return string
	 */
	public static function manage_podcast(): string {
		return apply_filters( 'epm_cap_manage_podcast', 'manage_options' );
	}

	/**
	 * Capability required to manage (create/edit/publish) episodes.
	 * Editors can normally manage episodes; filterable per site.
	 *
	 * @return string
	 */
	public static function manage_episodes(): string {
		return apply_filters( 'epm_cap_manage_episodes', 'edit_posts' );
	}

	/**
	 * Whether the current user may manage podcast settings/design.
	 *
	 * @return bool
	 */
	public static function can_manage_podcast(): bool {
		return current_user_can( self::manage_podcast() );
	}

	/**
	 * Whether the current user may manage episodes.
	 *
	 * @return bool
	 */
	public static function can_manage_episodes(): bool {
		return current_user_can( self::manage_episodes() );
	}
}
