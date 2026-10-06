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
				$names = array();
				$all   = wp_roles()->get_names();
				foreach ( $roles as $role ) {
					$names[] = isset( $all[ $role ] ) ? translate_user_role( $all[ $role ] ) : $role;
				}
				$data          = Honk_Notifier::user_data( $user );
				$data['roles'] = implode( ', ', $names );
				return Honk_Notifier::with_link(
					Honk_Details::fields(
						'user_registered',
						self::registration_spec( $data ),
						array(
							'group_key' => 'wp/users/registrations',
							'metadata'  => array( 'user_id' => $user->ID ),
						)
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
				$spec = self::comment_spec(
					array(
						'post'  => self::post_title( $post ),
						'name'  => trim( (string) $comment->comment_author ),
						'email' => (string) $comment->comment_author_email,
						'text'  => Honk_Details::on( 'comment_pending', 'text' ) ? wp_trim_words( Honk_Payload::plain( $comment->comment_content, true ), 40, '…' ) : '',
					)
				);
				return Honk_Notifier::with_link(
					Honk_Details::fields(
						'comment_pending',
						$spec,
						array(
							'group_key' => 'wp/comments/moderation',
							'metadata'  => array(
								'comment_id' => (int) $comment->comment_ID,
								'post_id'    => (int) $post->ID,
							),
						)
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
						Honk_Details::fields( 'post_pending', self::post_spec( self::post_data( 'post_pending', $post, $type ), true ) ) + array(
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
						Honk_Details::fields( 'post_published', self::post_spec( self::post_data( 'post_published', $post, $type ), false ) ) + array(
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
	 * What a message can say about a post.
	 *
	 * @param string       $event_id Event id.
	 * @param WP_Post      $post     Post.
	 * @param WP_Post_Type $type     Post type.
	 * @return array{title: string, type: string, author: string, excerpt: string}
	 */
	private static function post_data( $event_id, $post, $type ) {
		$excerpt = '';
		if ( Honk_Details::on( $event_id, 'excerpt' ) ) {
			$text = '' !== trim( (string) $post->post_excerpt ) ? (string) $post->post_excerpt : (string) $post->post_content;
			if ( function_exists( 'excerpt_remove_blocks' ) ) {
				$text = excerpt_remove_blocks( $text );
			}
			$excerpt = wp_trim_words( Honk_Payload::plain( strip_shortcodes( $text ), true ), 40, '…' );
		}
		return array(
			'title'   => self::post_title( $post ),
			'type'    => (string) $type->labels->singular_name,
			'author'  => Honk_Notifier::user_label( (int) $post->post_author, false ),
			'excerpt' => $excerpt,
		);
	}

	/*
	 * Message outlines (Honk_Details): the same for real events and for the settings preview.
	 */

	/**
	 * New user registration (Honk_Notifier::user_data() plus roles).
	 *
	 * @param array $d User data.
	 * @return array
	 */
	public static function registration_spec( array $d ) {
		return array(
			'title'    => array( Honk_Details::part( __( 'New user registration', 'honk' ) ) ),
			'lines'    => array(
				Honk_Notifier::user_line( $d ),
				/* translators: %s: role names */
				Honk_Details::text( '' !== $d['roles'] ? sprintf( __( 'Role: %s', 'honk' ), $d['roles'] ) : '', 'role' ),
			),
			'fallback' => __( 'A new user account was created.', 'honk' ),
		);
	}

	/**
	 * Comment awaiting moderation (post, name, email, text).
	 *
	 * @param array $d Comment data.
	 * @return array
	 */
	public static function comment_spec( array $d ) {
		return array(
			'title' => array( Honk_Details::part( __( 'Comment awaiting moderation', 'honk' ) ) ),
			'lines' => array(
				/* translators: %s: post title */
				Honk_Details::text( sprintf( __( 'On “%s”', 'honk' ), $d['post'] ), 'post' ),
				Honk_Details::line(
					array(
						Honk_Details::part( $d['name'], 'name' ),
						Honk_Details::part( $d['email'], 'email' ),
					),
					' · ',
					array(
						'any'   => array( 'name', 'email' ),
						'empty' => __( 'Anonymous', 'honk' ),
					)
				),
				Honk_Details::text( $d['text'], 'text' ),
			),
		);
	}

	/**
	 * Post pending review, or published (post_data()).
	 *
	 * @param array $d       Post data.
	 * @param bool  $pending Pending review (true) or published.
	 * @return array
	 */
	public static function post_spec( array $d, $pending ) {
		if ( $pending ) {
			/* translators: %s: post title */
			$title = sprintf( __( 'Pending review: %s', 'honk' ), $d['title'] );
			/* translators: 1: post type name (e.g. Post), 2: author */
			$by = sprintf( __( '%1$s by %2$s is waiting for review.', 'honk' ), $d['type'], $d['author'] );
			/* translators: %s: post type name (e.g. Post) */
			$plain = sprintf( __( '%s is waiting for review.', 'honk' ), $d['type'] );
		} else {
			/* translators: %s: post title */
			$title = sprintf( __( 'Published: %s', 'honk' ), $d['title'] );
			/* translators: 1: post type name (e.g. Post), 2: author */
			$by    = sprintf( __( '%1$s by %2$s.', 'honk' ), $d['type'], $d['author'] );
			$plain = $d['type'];
		}
		return array(
			'title' => array( Honk_Details::part( $title ) ),
			'lines' => array(
				Honk_Details::line( array( Honk_Details::part( $by, 'author' ), Honk_Details::part( $plain, array(), 'author' ) ) ),
				Honk_Details::text( $d['excerpt'], 'excerpt' ),
			),
		);
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
