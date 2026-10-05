<?php
/**
 * Content and user events: registrations, comments awaiting moderation, posts.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content module.
 */
final class Honk_Module_Content {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 30, 1 );
		add_action( 'wp_insert_comment', array( __CLASS__, 'on_insert_comment' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 20, 3 );
	}

	/**
	 * A user registered. Administrators are reported by the security module, and WooCommerce
	 * customers by the WooCommerce module when that event is on.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function on_user_register( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			return;
		}
		$roles = (array) $user->roles;
		if ( in_array( 'customer', $roles, true ) && Honk_Settings::is_enabled( 'woo_new_customer' ) ) {
			return;
		}
		Honk_Notifier::emit(
			'user_registered',
			'user-' . $user_id . '-registered',
			function () use ( $user, $roles ) {
				$pii   = Honk_Settings::include_pii();
				$names = array();
				$all   = wp_roles()->get_names();
				foreach ( $roles as $role ) {
					$names[] = isset( $all[ $role ] ) ? translate_user_role( $all[ $role ] ) : $role;
				}
				$lines = array();
				if ( $pii ) {
					$lines[] = Honk_Notifier::user_label( $user, true );
				}
				if ( $names ) {
					/* translators: %s: role names */
					$lines[] = sprintf( __( 'Role: %s', 'honk' ), implode( ', ', $names ) );
				}
				return Honk_Notifier::with_link(
					array(
						'title'     => __( 'New user registration', 'honk' ),
						'message'   => $lines ? implode( "\n", $lines ) : __( 'A new user account was created.', 'honk' ),
						'group_key' => 'wp/users/registrations',
						'pii'       => $pii,
						'metadata'  => array( 'user_id' => $user->ID ),
					),
					admin_url( 'user-edit.php?user_id=' . $user->ID )
				);
			}
		);
	}

	/**
	 * A comment was saved: reported when it waits for moderation. Product reviews are reported by
	 * the WooCommerce module when that event is on.
	 *
	 * @param int        $comment_id Comment id.
	 * @param WP_Comment $comment    Comment.
	 * @return void
	 */
	public static function on_insert_comment( $comment_id, $comment ) {
		if ( ! $comment instanceof WP_Comment || '0' !== (string) $comment->comment_approved ) {
			return;
		}
		if ( ! in_array( $comment->comment_type, array( '', 'comment', 'pingback', 'trackback', 'review' ), true ) ) {
			return;
		}
		$post = get_post( (int) $comment->comment_post_ID );
		if ( ! $post ) {
			return;
		}
		if ( 'product' === $post->post_type && Honk_Settings::is_enabled( 'woo_new_review' ) ) {
			return;
		}
		Honk_Notifier::emit(
			'comment_pending',
			'comment-' . $comment_id . '-pending',
			function () use ( $comment, $post ) {
				$pii   = Honk_Settings::include_pii();
				$lines = array(
					/* translators: %s: post title */
					sprintf( __( 'On “%s”', 'honk' ), self::post_title( $post ) ),
				);
				if ( $pii ) {
					$author  = trim( $comment->comment_author . ( $comment->comment_author_email ? ' · ' . $comment->comment_author_email : '' ) );
					$lines[] = '' !== $author ? $author : __( 'Anonymous', 'honk' );
					$lines[] = wp_trim_words( Honk_Payload::plain( $comment->comment_content, true ), 40, '…' );
				}
				return Honk_Notifier::with_link(
					array(
						'title'     => __( 'Comment awaiting moderation', 'honk' ),
						'message'   => implode( "\n", $lines ),
						'group_key' => 'wp/comments/moderation',
						'pii'       => $pii,
						'metadata'  => array(
							'comment_id' => (int) $comment->comment_ID,
							'post_id'    => (int) $post->ID,
						),
					),
					admin_url( 'edit-comments.php?comment_status=moderated' )
				);
			}
		);
	}

	/**
	 * A post changed status: pending review, or published for the first time.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || $new_status === $old_status || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public || 'attachment' === $post->post_type ) {
			return;
		}

		if ( 'pending' === $new_status ) {
			Honk_Notifier::emit(
				'post_pending',
				'post-' . $post->ID . '-pending-' . strtotime( $post->post_modified_gmt . ' UTC' ),
				function () use ( $post, $type ) {
					return Honk_Notifier::with_link(
						array(
							/* translators: %s: post title */
							'title'     => sprintf( __( 'Pending review: %s', 'honk' ), self::post_title( $post ) ),
							'message'   => sprintf(
								/* translators: 1: post type name (e.g. Post), 2: author */
								__( '%1$s by %2$s is waiting for review.', 'honk' ),
								$type->labels->singular_name,
								Honk_Notifier::user_label( (int) $post->post_author, false )
							),
							'group_key' => 'wp/posts/pending',
							'metadata'  => array(
								'post_id'   => (int) $post->ID,
								'post_type' => $post->post_type,
							),
						),
						admin_url( 'post.php?post=' . $post->ID . '&action=edit' )
					);
				}
			);
			return;
		}

		if ( 'publish' === $new_status ) {
			Honk_Notifier::emit(
				'post_published',
				'post-' . $post->ID . '-published',
				function () use ( $post, $type ) {
					return Honk_Notifier::with_link(
						array(
							/* translators: %s: post title */
							'title'     => sprintf( __( 'Published: %s', 'honk' ), self::post_title( $post ) ),
							'message'   => sprintf(
								/* translators: 1: post type name (e.g. Post), 2: author */
								__( '%1$s by %2$s.', 'honk' ),
								$type->labels->singular_name,
								Honk_Notifier::user_label( (int) $post->post_author, false )
							),
							'group_key' => 'wp/posts/published',
							'metadata'  => array(
								'post_id'   => (int) $post->ID,
								'post_type' => $post->post_type,
							),
						),
						(string) get_permalink( $post )
					);
				}
			);
		}
	}

	/**
	 * A post's title as plain text, or "(no title)".
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function post_title( $post ) {
		$title = Honk_Payload::plain( get_the_title( $post ), true );
		return '' !== $title ? $title : __( '(no title)', 'honk' );
	}
}
