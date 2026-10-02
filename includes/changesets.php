<?php
/**
 * Changeset architecture: staging session for site edits.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register CPT changeset.
 */
function cs_register_changeset_cpt() {
	register_post_type(
		'changeset',
		array(
			'labels'              => array(
				'name'               => __( 'Changesets', 'changesets' ),
				'singular_name'      => __( 'Changeset', 'changesets' ),
				'add_new'            => __( 'Add New', 'changesets' ),
				'add_new_item'       => __( 'Add New Changeset', 'changesets' ),
				'edit_item'          => __( 'Edit Changeset', 'changesets' ),
				'new_item'           => __( 'New Changeset', 'changesets' ),
				'view_item'          => __( 'View Changeset', 'changesets' ),
				'search_items'       => __( 'Search Changesets', 'changesets' ),
				'not_found'          => __( 'No changesets found', 'changesets' ),
				'not_found_in_trash' => __( 'No changesets found in Trash', 'changesets' ),
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'supports'            => array( 'title' ),
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'menu_icon'           => 'dashicons-clipboard',
			'show_in_rest'        => true,
		)
	);
}
add_action( 'init', 'cs_register_changeset_cpt' );

/**
 * Get or create the current open changeset.
 *
 * @param string|null $title Optional title for new changeset.
 * @return int|WP_Error Changeset ID.
 */
function cs_get_open_changeset( $title = null ) {
	$existing = get_posts(
		array(
			'post_type'      => 'changeset',
			'post_status'    => 'draft',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => '_changeset_status',
					'value' => 'open',
				),
			),
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);

	if ( $existing ) {
		return $existing[0]->ID;
	}

	return cs_create_changeset( $title );
}

/**
 * Create a new changeset.
 *
 * @param string|null $title Optional title.
 * @return int|WP_Error Changeset ID.
 */
function cs_create_changeset( $title = null ) {
	if ( ! $title ) {
		$title = sprintf(
			/* translators: %s: date */
			__( 'Changeset %s', 'changesets' ),
			gmdate( 'Y-m-d H:i' )
		);
	}

	$changeset_id = wp_insert_post(
		array(
			'post_type'   => 'changeset',
			'post_status' => 'draft',
			'post_title'  => $title,
		),
		true
	);

	if ( is_wp_error( $changeset_id ) ) {
		return $changeset_id;
	}

	$uuid = wp_generate_uuid4();
	update_post_meta( $changeset_id, '_changeset_uuid', $uuid );
	update_post_meta( $changeset_id, '_changeset_status', 'open' );

	return $changeset_id;
}


/**
 * Update a changeset title (agent-owned session label).
 *
 * @param int    $changeset_id Changeset ID.
 * @param string $title        New title.
 * @return true|WP_Error
 */
function cs_update_changeset_title( $changeset_id, $title ) {
	$changeset = cs_get_changeset( $changeset_id );
	if ( ! $changeset ) {
		return new WP_Error( 'cs_invalid_changeset', __( 'Invalid changeset.', 'changesets' ) );
	}
	$title = trim( (string) $title );
	if ( '' === $title ) {
		return new WP_Error( 'cs_missing_title', __( 'Title is required.', 'changesets' ) );
	}
	$result = wp_update_post(
		array(
			'ID'         => (int) $changeset_id,
			'post_title' => $title,
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return true;
}

/**
 * Get changeset by ID or UUID.
 *
 * @param int|string $changeset_id_or_uuid Changeset ID or UUID.
 * @return WP_Post|null
 */
function cs_get_changeset( $changeset_id_or_uuid ) {
	if ( is_numeric( $changeset_id_or_uuid ) ) {
		$post = get_post( (int) $changeset_id_or_uuid );
		if ( $post && 'changeset' === $post->post_type ) {
			return $post;
		}
		return null;
	}

	$changesets = get_posts(
		array(
			'post_type'      => 'changeset',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => '_changeset_uuid',
					'value' => $changeset_id_or_uuid,
				),
			),
		)
	);

	return $changesets ? $changesets[0] : null;
}

/**
 * Get changeset UUID.
 *
 * @param int $changeset_id Changeset ID.
 * @return string
 */
function cs_get_changeset_uuid( $changeset_id ) {
	return get_post_meta( (int) $changeset_id, '_changeset_uuid', true );
}

/**
 * Whether a post type is stageable.
 *
 * @param string $post_type Post type.
 * @return bool
 */
function cs_is_stageable_post_type( $post_type ) {
	// Exclude changeset CPT itself and attachments.
	if ( 'changeset' === $post_type || 'attachment' === $post_type ) {
		return false;
	}

	// Always allow core content types and FSE types.
	$core_types = array( 'page', 'post', 'wp_template', 'wp_template_part', 'wp_navigation' );
	if ( in_array( $post_type, $core_types, true ) ) {
		return true;
	}

	// Allow other public CPTs that have show_ui.
	$post_type_obj = get_post_type_object( $post_type );
	if ( ! $post_type_obj ) {
		return false;
	}

	return $post_type_obj->public && $post_type_obj->show_ui;
}

/**
 * Get changeset status.
 *
 * @param int $changeset_id Changeset ID.
 * @return string open|approved|published|discarded
 */
function cs_get_changeset_status( $changeset_id ) {
	$status = get_post_meta( (int) $changeset_id, '_changeset_status', true );
	return $status ? $status : 'open';
}

/**
 * Whether changeset is approved.
 *
 * @param int $changeset_id Changeset ID.
 * @return bool
 */
function cs_is_changeset_approved( $changeset_id ) {
	return 'approved' === cs_get_changeset_status( $changeset_id );
}

/**
 * Approve changeset (human gate).
 *
 * @param int $changeset_id Changeset ID.
 * @return true|WP_Error
 */
function cs_approve_changeset( $changeset_id ) {
	$changeset = cs_get_changeset( $changeset_id );
	if ( ! $changeset ) {
		return new WP_Error( 'cs_not_changeset', __( 'Not a changeset.', 'changesets' ) );
	}

	if ( ! cs_user_can_approve_changeset( $changeset_id ) ) {
		return new WP_Error( 'cs_forbidden', __( 'You cannot approve this changeset.', 'changesets' ) );
	}

	if ( ! in_array( cs_get_changeset_status( $changeset_id ), array( 'open', 'approved' ), true ) ) {
		return new WP_Error( 'cs_closed_changeset', __( 'Cannot approve a closed changeset.', 'changesets' ) );
	}

	update_post_meta( $changeset_id, '_changeset_status', 'approved' );
	update_post_meta( $changeset_id, '_changeset_approved_by', get_current_user_id() );
	update_post_meta( $changeset_id, '_changeset_approved_at', gmdate( 'c' ) );
	wp_update_post( array( 'ID' => $changeset_id, 'post_status' => 'pending' ) );

	return true;
}

/**
 * Whether staged entity draft.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function cs_is_staged( $post_id ) {
	return (bool) get_post_meta( (int) $post_id, '_changeset_is_staged', true );
}

/**
 * Get source post ID from staged draft.
 *
 * @param int $staged_id Staged draft ID.
 * @return int Source post ID or 0.
 */
function cs_get_staged_source_id( $staged_id ) {
	return (int) get_post_meta( (int) $staged_id, CS_META_SOURCE, true );
}

/**
 * Get changeset ID from staged draft.
 *
 * @param int $staged_id Staged draft ID.
 * @return int
 */
function cs_get_staged_changeset_id( $staged_id ) {
	return (int) get_post_meta( (int) $staged_id, '_changeset_id', true );
}

/**
 * Stage content: clone a published post/page/template/etc into changeset.
 *
 * @param int    $changeset_id Changeset ID.
 * @param int    $source_id    Source post ID.
 * @param string $post_type    Optional post type (defaults to source's type).
 * @return int|WP_Error Staged draft ID.
 */
function cs_stage_content( $changeset_id, $source_id, $post_type = '' ) {
	$changeset_id = (int) $changeset_id;
	$source_id    = (int) $source_id;

	$changeset = cs_get_changeset( $changeset_id );
	if ( ! $changeset ) {
		return new WP_Error( 'cs_invalid_changeset', __( 'Invalid changeset.', 'changesets' ) );
	}

	$source = get_post( $source_id );
	if ( ! $source ) {
		return new WP_Error( 'cs_invalid_source', __( 'Source post not found.', 'changesets' ) );
	}

	// Use source's post type if not specified.
	if ( ! $post_type ) {
		$post_type = $source->post_type;
	}

	if ( ! cs_is_stageable_post_type( $post_type ) ) {
		return new WP_Error( 'cs_unsupported_type', sprintf( __( 'Post type "%s" is not stageable.', 'changesets' ), $post_type ) );
	}

	if ( cs_is_staged( $source_id ) ) {
		return new WP_Error( 'cs_source_is_staged', __( 'Cannot stage another staged draft.', 'changesets' ) );
	}

	$existing = cs_get_staged_draft_for_source( $changeset_id, $source_id );
	if ( $existing ) {
		return new WP_Error(
			'cs_already_staged',
			__( 'This content is already staged in this changeset.', 'changesets' ),
			array( 'staged_id' => $existing )
		);
	}

	$staged_id = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => $source->post_title,
			'post_content' => $source->post_content,
			'post_excerpt' => $source->post_excerpt,
			'post_author'  => get_current_user_id() ? get_current_user_id() : $source->post_author,
			'post_parent'  => $source->post_parent,
			'menu_order'   => $source->menu_order,
			'post_name'    => '',
		),
		true
	);

	if ( is_wp_error( $staged_id ) ) {
		return $staged_id;
	}

	update_post_meta( $staged_id, '_changeset_is_staged', 1 );
	update_post_meta( $staged_id, CS_META_SOURCE, $source_id );
	update_post_meta( $staged_id, '_changeset_id', $changeset_id );

	$thumb = get_post_thumbnail_id( $source_id );
	if ( $thumb ) {
		set_post_thumbnail( $staged_id, $thumb );
	}

	// Copy taxonomy terms for pages/posts.
	if ( in_array( $post_type, array( 'page', 'post' ), true ) ) {
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $staged_id, $terms, $taxonomy );
			}
		}
	}

	return $staged_id;
}



/**
 * Get staged options bag for a changeset.
 *
 * @param int $changeset_id Changeset ID.
 * @return array
 */
function cs_get_staged_options( $changeset_id ) {
	$raw = get_post_meta( (int) $changeset_id, '_changeset_staged_options', true );
	return is_array( $raw ) ? $raw : array();
}

/**
 * Stage a site option or theme_mod into a changeset (not applied live until Publish).
 *
 * Uses a denylist approach: most options and theme_mods are stageable unless they affect
 * bootstrap, authentication, or core site behavior. Denylisted keys are rejected with
 * WP_Error; all others are accepted.
 *
 * @param int    $changeset_id Changeset ID.
 * @param string $key          Option or theme_mod name.
 * @param mixed  $value        Option value.
 * @param string $store        Optional. 'option' (default) or 'theme_mod'.
 * @return true|WP_Error
 */
function cs_stage_option( $changeset_id, $key, $value, $store = 'option' ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'cs_forbidden', __( 'You cannot stage settings.', 'changesets' ) );
	}
	$changeset = cs_get_changeset( $changeset_id );
	if ( ! $changeset ) {
		return new WP_Error( 'cs_invalid_changeset', __( 'Invalid changeset.', 'changesets' ) );
	}

	// Normalize store type.
	$store = in_array( $store, array( 'option', 'theme_mod' ), true ) ? $store : 'option';

	// Auto-detect known theme_mod keys.
	$theme_mod_keys = array( 'custom_logo' );
	if ( in_array( $key, $theme_mod_keys, true ) ) {
		$store = 'theme_mod';
	}

	if ( ! cs_is_safe_staged_option( $key, $store ) ) {
		return new WP_Error( 'cs_denylisted_option', __( 'This option cannot be staged.', 'changesets' ) );
	}

	// Type conversion for known post/attachment reference keys.
	if ( in_array( $key, array( 'page_on_front', 'page_for_posts', 'site_icon', 'custom_logo' ), true ) ) {
		$value = (int) $value;
	} else {
		$value = is_string( $value ) ? $value : (string) $value;
	}

	$bag = cs_get_staged_options( $changeset_id );
	$bag[ $key ] = array(
		'value' => $value,
		'store' => $store,
	);
	update_post_meta( (int) $changeset_id, '_changeset_staged_options', $bag );
	return true;
}



/**
 * Get staged global styles (user theme.json array) for a changeset.
 *
 * @param int $changeset_id Changeset ID.
 * @return array|null
 */
function cs_get_staged_global_styles( $changeset_id ) {
	$raw = get_post_meta( (int) $changeset_id, '_changeset_staged_global_styles', true );
	if ( is_array( $raw ) ) {
		return $raw;
	}
	if ( is_string( $raw ) && $raw ) {
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}
	return null;
}

/**
 * Save staged global styles JSON onto a changeset.
 *
 * @param int   $changeset_id Changeset ID.
 * @param array $data         User theme.json-shaped data.
 * @return true|WP_Error
 */
function cs_set_staged_global_styles( $changeset_id, $data ) {
	$changeset = cs_get_changeset( $changeset_id );
	if ( ! $changeset ) {
		return new WP_Error( 'cs_invalid_changeset', __( 'Invalid changeset.', 'changesets' ) );
	}
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'cs_invalid_styles', __( 'Global styles must be an object.', 'changesets' ) );
	}
	$data['isGlobalStylesUserThemeJSON'] = true;
	if ( empty( $data['version'] ) ) {
		$data['version'] = 3;
	}
	update_post_meta( (int) $changeset_id, '_changeset_staged_global_styles', $data );
	return true;
}

/**
 * Stage arbitrary global styles into a changeset (merge onto current staged or live user styles).
 *
 * @param int   $changeset_id Changeset ID.
 * @param array $patch        Partial theme.json (settings/styles/etc).
 * @return array|WP_Error
 */
function cs_stage_global_styles( $changeset_id, $patch ) {
	$changeset = cs_get_changeset( $changeset_id );
	if ( ! $changeset ) {
		return new WP_Error( 'cs_invalid_changeset', __( 'Invalid changeset.', 'changesets' ) );
	}
	if ( ! is_array( $patch ) ) {
		return new WP_Error( 'cs_invalid_styles', __( 'Global styles patch must be an object.', 'changesets' ) );
	}

	$base = cs_get_staged_global_styles( $changeset_id );
	if ( ! $base ) {
		$base = array(
			'version'                       => 3,
			'isGlobalStylesUserThemeJSON'   => true,
		);
		// Seed from live user global styles when present.
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			$user = WP_Theme_JSON_Resolver::get_user_data();
			if ( $user && method_exists( $user, 'get_raw_data' ) ) {
				$raw = $user->get_raw_data();
				if ( is_array( $raw ) && $raw ) {
					$base = $raw;
					$base['isGlobalStylesUserThemeJSON'] = true;
				}
			}
		}
	}

	// Shallow+nested merge for settings/styles keys.
	foreach ( $patch as $key => $value ) {
		if ( in_array( $key, array( 'settings', 'styles' ), true ) && is_array( $value ) ) {
			$existing = isset( $base[ $key ] ) && is_array( $base[ $key ] ) ? $base[ $key ] : array();
			$base[ $key ] = array_replace_recursive( $existing, $value );
		} elseif ( '$schema' !== $key ) {
			$base[ $key ] = $value;
		}
	}

	$result = cs_set_staged_global_styles( $changeset_id, $base );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'changeset_id' => (int) $changeset_id,
		'staged'       => true,
		'styles'       => $base,
	);
}

/**
 * Resolve a theme style variation by file stem or title.
 *
 * @param string $variation File stem (e.g. 05-twilight) or title (e.g. Twilight).
 * @return array|WP_Error { stem, title, data }
 */
function cs_resolve_style_variation( $variation ) {
	$variation = trim( (string) $variation );
	if ( '' === $variation ) {
		return new WP_Error( 'cs_missing_variation', __( 'Style variation is required.', 'changesets' ) );
	}

	$dir = trailingslashit( get_stylesheet_directory() ) . 'styles/';
	if ( ! is_dir( $dir ) ) {
		return new WP_Error( 'cs_no_variations', __( 'This theme has no style variations.', 'changesets' ) );
	}

	$files = glob( $dir . '*.json' );
	if ( ! $files ) {
		return new WP_Error( 'cs_no_variations', __( 'This theme has no style variations.', 'changesets' ) );
	}

	foreach ( $files as $file ) {
		$stem = basename( $file, '.json' );
		$raw  = file_get_contents( $file );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			continue;
		}
		$title = isset( $data['title'] ) ? (string) $data['title'] : $stem;
		if ( strcasecmp( $stem, $variation ) === 0 || strcasecmp( $title, $variation ) === 0 ) {
			unset( $data['$schema'] );
			return array(
				'stem'  => $stem,
				'title' => $title,
				'data'  => $data,
			);
		}
	}

	return new WP_Error( 'cs_unknown_variation', __( 'Style variation not found.', 'changesets' ) );
}

/**
 * Stage a theme style variation into a changeset.
 *
 * @param int    $changeset_id Changeset ID.
 * @param string $variation    File stem or title.
 * @return array|WP_Error
 */
function cs_stage_style_variation( $changeset_id, $variation ) {
	$changeset = cs_get_changeset( $changeset_id );
	if ( ! $changeset ) {
		return new WP_Error( 'cs_invalid_changeset', __( 'Invalid changeset.', 'changesets' ) );
	}

	$resolved = cs_resolve_style_variation( $variation );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	$result = cs_set_staged_global_styles( $changeset_id, $resolved['data'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	update_post_meta( (int) $changeset_id, '_changeset_staged_style_variation', $resolved['stem'] );
	update_post_meta( (int) $changeset_id, '_changeset_staged_style_variation_title', $resolved['title'] );

	return array(
		'changeset_id' => (int) $changeset_id,
		'stem'         => $resolved['stem'],
		'title'        => $resolved['title'],
		'staged'       => true,
	);
}

/**
 * Get staged style variation stem for a changeset.
 *
 * @param int $changeset_id Changeset ID.
 * @return string
 */
function cs_get_staged_style_variation( $changeset_id ) {
	return (string) get_post_meta( (int) $changeset_id, '_changeset_staged_style_variation', true );
}

/**
 * Create brand-new content that lives only inside a changeset (no live source).
 *
 * @param int    $changeset_id Changeset ID.
 * @param string $post_type    Post type (page, post, wp_template, etc).
 * @param string $title        Title.
 * @param string $content      Optional content.
 * @param string $slug         Optional slug.
 * @param string $theme        Optional theme slug (for templates).
 * @return int|WP_Error Staged content ID.
 */
function cs_create_staged_content( $changeset_id, $post_type, $title = '', $content = '', $slug = '', $theme = '' ) {
	$changeset_id = (int) $changeset_id;

	$changeset = cs_get_changeset( $changeset_id );
	if ( ! $changeset ) {
		return new WP_Error( 'cs_invalid_changeset', __( 'Invalid changeset.', 'changesets' ) );
	}

	if ( ! cs_is_stageable_post_type( $post_type ) ) {
		return new WP_Error( 'cs_unsupported_type', sprintf( __( 'Post type "%s" is not stageable.', 'changesets' ), $post_type ) );
	}

	// For templates, handle theme slug.
	if ( 'wp_template' === $post_type || 'wp_template_part' === $post_type ) {
		if ( ! $theme ) {
			$theme = get_stylesheet();
		}
		if ( ! $slug ) {
			$slug = sanitize_title( $title );
		}
	} else {
		$slug = $slug ? sanitize_title( $slug ) : sanitize_title( $title );
	}

	$post_data = array(
		'post_type'    => $post_type,
		'post_status'  => 'draft',
		'post_title'   => $title,
		'post_content' => $content,
		'post_author'  => get_current_user_id() ? get_current_user_id() : 1,
		'post_name'    => $slug,
	);

	$staged_id = wp_insert_post( $post_data, true );

	if ( is_wp_error( $staged_id ) ) {
		return $staged_id;
	}

	update_post_meta( $staged_id, '_changeset_is_staged', 1 );
	update_post_meta( $staged_id, '_changeset_id', $changeset_id );
	update_post_meta( $staged_id, CS_META_SOURCE, 0 );

	// For templates, store theme.
	if ( ( 'wp_template' === $post_type || 'wp_template_part' === $post_type ) && $theme ) {
		update_post_meta( $staged_id, 'theme', $theme );
	}

	return $staged_id;
}

/**
 * Create a brand-new page that lives only inside a changeset (no live source).
 *
 * @deprecated 0.4.0 Use cs_create_staged_content() instead.
 * @param int    $changeset_id Changeset ID.
 * @param string $title        Page title.
 * @param string $content      Optional content.
 * @param string $slug         Optional slug.
 * @return int|WP_Error Staged page ID.
 */
function cs_create_staged_page( $changeset_id, $title, $content = '', $slug = '' ) {
	return cs_create_staged_content( $changeset_id, 'page', $title, $content, $slug );
}

/**
 * Get staged draft for a source in a changeset.
 *
 * @param int $changeset_id Changeset ID.
 * @param int $source_id    Source post ID.
 * @return int Staged draft ID or 0.
 */
function cs_get_staged_draft_for_source( $changeset_id, $source_id ) {
	$staged = get_posts(
		array(
			'post_type'        => 'any',
			'post_status'      => 'draft',
			'posts_per_page'   => 1,
			'cs_internal'      => true,
			'suppress_filters' => true,
			'meta_query'       => array(
				array(
					'key'   => '_changeset_id',
					'value' => (int) $changeset_id,
				),
				array(
					'key'   => CS_META_SOURCE,
					'value' => (int) $source_id,
				),
				array(
					'key'   => '_changeset_is_staged',
					'value' => '1',
				),
			),
			'fields'           => 'ids',
		)
	);

	return $staged ? $staged[0] : 0;
}

/**
 * Get all staged drafts in a changeset.
 *
 * @param int $changeset_id Changeset ID.
 * @return array Array of staged draft IDs.
 */
function cs_get_staged_drafts( $changeset_id ) {
	return get_posts(
		array(
			'post_type'      => 'any',
			'post_status'    => 'draft',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'   => '_changeset_id',
					'value' => (int) $changeset_id,
				),
				array(
					'key'   => '_changeset_is_staged',
					'value' => '1',
				),
			),
			'fields'         => 'ids',
		)
	);
}

/**
 * Update staged content.
 *
 * @param int   $staged_id Staged draft ID.
 * @param array $fields    Keys: title, content, excerpt.
 * @return true|WP_Error
 */
function cs_update_staged_content( $staged_id, $fields ) {
	$staged_id = (int) $staged_id;
	if ( ! cs_is_staged( $staged_id ) ) {
		return new WP_Error( 'cs_not_staged', __( 'Not a staged draft.', 'changesets' ) );
	}

	$update = array( 'ID' => $staged_id );
	if ( isset( $fields['title'] ) ) {
		$update['post_title'] = $fields['title'];
	}
	if ( isset( $fields['content'] ) ) {
		$update['post_content'] = $fields['content'];
	}
	if ( isset( $fields['excerpt'] ) ) {
		$update['post_excerpt'] = $fields['excerpt'];
	}

	if ( count( $update ) === 1 ) {
		return new WP_Error( 'cs_no_fields', __( 'No fields to update.', 'changesets' ) );
	}

	$result = wp_update_post( $update, true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return true;
}

/**
 * Publish changeset: apply all staged drafts to live, then close.
 *
 * @param int $changeset_id Changeset ID.
 * @return array|WP_Error { applied_count, source_ids }
 */
function cs_publish_changeset( $changeset_id ) {
	global $cs_publishing_changeset;

	$changeset_id = (int) $changeset_id;
	$changeset    = cs_get_changeset( $changeset_id );

	if ( ! $changeset ) {
		return new WP_Error( 'cs_not_changeset', __( 'Not a changeset.', 'changesets' ) );
	}

	if ( ! cs_user_can_publish_changeset( $changeset_id ) ) {
		return new WP_Error( 'cs_forbidden', __( 'You cannot publish this changeset.', 'changesets' ) );
	}
	if ( ! cs_is_changeset_approved( $changeset_id ) ) {
		return new WP_Error( 'cs_not_approved', __( 'Approve the changeset before publishing.', 'changesets' ) );
	}
	$options = cs_get_staged_options( $changeset_id );
	if ( $options && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'cs_forbidden', __( 'You cannot publish settings.', 'changesets' ) );
	}
	foreach ( $options as $key => $item ) {
		$store = is_array( $item ) && isset( $item['store'] ) ? $item['store'] : 'option';
		if ( ! cs_is_safe_staged_option( $key, $store ) ) {
			return new WP_Error( 'cs_denylisted_option', __( 'This changeset contains an unsafe option.', 'changesets' ) );
		}
	}

	// Set internal flag to bypass staged-publish guard during this operation.
	$cs_publishing_changeset = true;

	$staged_ids      = cs_get_staged_drafts( $changeset_id );
	$applied         = 0;
	$published_new   = 0;
	$source_ids      = array();
	$staged_to_live  = array();
	$failed_items    = array();

	foreach ( $staged_ids as $staged_id ) {
		$staged    = get_post( $staged_id );
		$source_id = cs_get_staged_source_id( $staged_id );

		if ( ! $staged ) {
			continue;
		}

		if ( $source_id > 0 ) {
			$source = get_post( $source_id );
			if ( ! $source ) {
				$failed_items[] = array(
					'staged_id' => $staged_id,
					'reason'    => 'Source post not found',
				);
				continue;
			}

			// For templates/parts/navigation, they might be in 'publish' or 'auto-draft' status.
			// For pages/posts, require 'publish' status.
			$requires_publish = in_array( $staged->post_type, array( 'page', 'post' ), true );
			if ( $requires_publish && 'publish' !== $source->post_status ) {
				$failed_items[] = array(
					'staged_id' => $staged_id,
					'source_id' => $source_id,
					'reason'    => 'Source is not published',
				);
				continue;
			}

			wp_save_post_revision( $source_id );

			wp_update_post(
				array(
					'ID'           => $source_id,
					'post_title'   => $staged->post_title,
					'post_content' => $staged->post_content,
					'post_excerpt' => $staged->post_excerpt,
				),
				true
			);

			$thumb = get_post_thumbnail_id( $staged_id );
			if ( $thumb ) {
				set_post_thumbnail( $source_id, $thumb );
			} else {
				delete_post_thumbnail( $source_id );
			}

			foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
				if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $source->post_type, $taxonomy ) ) {
					continue;
				}
				$terms = wp_get_object_terms( $staged_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $terms ) ) {
					wp_set_object_terms( $source_id, $terms, $taxonomy );
				}
			}

			wp_delete_post( $staged_id, true );
			$staged_to_live[ $staged_id ] = $source_id;
			$source_ids[] = $source_id;
			$applied++;
		} else {
			// Promote brand-new staged content to live publish.
			// Clear staged markers FIRST to prevent guard from blocking.
			delete_post_meta( $staged_id, '_changeset_is_staged' );
			delete_post_meta( $staged_id, '_changeset_id' );
			delete_post_meta( $staged_id, CS_META_SOURCE );

			$result = wp_update_post(
				array(
					'ID'          => $staged_id,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				$failed_items[] = array(
					'staged_id' => $staged_id,
					'reason'    => $result->get_error_message(),
				);
				continue;
			}

			// Verify final status is publish.
			$published_post = get_post( $staged_id );
			if ( ! $published_post || 'publish' !== $published_post->post_status ) {
				$failed_items[] = array(
					'staged_id'    => $staged_id,
					'reason'       => 'Failed to reach publish status',
					'final_status' => $published_post ? $published_post->post_status : 'unknown',
				);
				continue;
			}

			$staged_to_live[ $staged_id ] = $staged_id;
			$source_ids[] = $staged_id;
			$published_new++;
		}
	}

	// Apply settings with ID remapping AFTER content is published.
	$options = cs_get_staged_options( $changeset_id );
	if ( $options ) {
		foreach ( $options as $key => $item ) {
			// Backward compatibility: direct values (0.4.2) vs new structure (0.5.0+).
			if ( is_array( $item ) && isset( $item['value'] ) ) {
				$value = $item['value'];
				$store = isset( $item['store'] ) ? $item['store'] : 'option';
			} else {
				// Old format: direct value, assume option store.
				$value = $item;
				$store = 'option';
			}

			// Remap staged IDs to live IDs for settings that reference posts.
			if ( in_array( $key, array( 'page_on_front', 'page_for_posts', 'site_icon', 'custom_logo' ), true ) && isset( $staged_to_live[ $value ] ) ) {
				$value = $staged_to_live[ $value ];
			}

			if ( 'theme_mod' === $store ) {
				set_theme_mod( $key, $value );
			} else {
				update_option( $key, $value );
			}
		}
		delete_post_meta( $changeset_id, '_changeset_staged_options' );
	}

	$staged_styles = cs_get_staged_global_styles( $changeset_id );
	if ( ! $staged_styles ) {
		$variation_stem = cs_get_staged_style_variation( $changeset_id );
		if ( $variation_stem ) {
			$resolved = cs_resolve_style_variation( $variation_stem );
			if ( ! is_wp_error( $resolved ) ) {
				$staged_styles = $resolved['data'];
			}
		}
	}
	if ( $staged_styles ) {
		$user_post = null;
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			$user_post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		}
		if ( is_array( $user_post ) && ! empty( $user_post['ID'] ) ) {
			$payload                               = $staged_styles;
			$payload['isGlobalStylesUserThemeJSON'] = true;
			$payload['version']                     = isset( $payload['version'] ) ? $payload['version'] : 3;
			wp_update_post(
				array(
					'ID'           => (int) $user_post['ID'],
					'post_content' => wp_slash( wp_json_encode( $payload ) ),
				),
				true
			);
		}
		delete_post_meta( $changeset_id, '_changeset_staged_global_styles' );
		delete_post_meta( $changeset_id, '_changeset_staged_style_variation' );
		delete_post_meta( $changeset_id, '_changeset_staged_style_variation_title' );
	}

	update_post_meta( $changeset_id, '_changeset_status', 'published' );
	update_post_meta( $changeset_id, '_changeset_published_at', gmdate( 'c' ) );
	update_post_meta( $changeset_id, '_changeset_published_by', get_current_user_id() );
	$cs_publishing_changeset = false;

	cs_clear_preview_cookie();

	$result = array(
		'changeset_id'        => $changeset_id,
		'applied_count'       => $applied,
		'published_new_count' => $published_new,
		'source_ids'          => $source_ids,
		'status'              => 'published',
	);

	if ( ! empty( $failed_items ) ) {
		$result['failed_items'] = $failed_items;
		$result['partial_success'] = true;
	}

	return $result;
}

/**
 * Discard changeset: trash the changeset and delete all staged drafts.
 *
 * @param int $changeset_id Changeset ID.
 * @return array|WP_Error { changeset_id, status, deleted_count }
 */
function cs_discard_changeset( $changeset_id ) {
	$changeset_id = (int) $changeset_id;
	$changeset    = cs_get_changeset( $changeset_id );

	if ( ! $changeset ) {
		return new WP_Error( 'cs_not_changeset', __( 'Not a changeset.', 'changesets' ) );
	}

	$status = cs_get_changeset_status( $changeset_id );
	if ( 'published' === $status ) {
		return new WP_Error( 'cs_already_published', __( 'Cannot discard a published changeset.', 'changesets' ) );
	}

	// Delete all staged drafts.
	$staged_ids = cs_get_staged_drafts( $changeset_id );
	$deleted_count = 0;
	foreach ( $staged_ids as $staged_id ) {
		if ( wp_delete_post( $staged_id, true ) ) {
			$deleted_count++;
		}
	}

	// Clear staged options and styles.
	delete_post_meta( $changeset_id, '_changeset_staged_options' );
	delete_post_meta( $changeset_id, '_changeset_staged_global_styles' );
	delete_post_meta( $changeset_id, '_changeset_staged_style_variation' );
	delete_post_meta( $changeset_id, '_changeset_staged_style_variation_title' );

	// Mark as discarded and trash.
	update_post_meta( $changeset_id, '_changeset_status', 'discarded' );
	update_post_meta( $changeset_id, '_changeset_discarded_at', gmdate( 'c' ) );
	update_post_meta( $changeset_id, '_changeset_discarded_by', get_current_user_id() );
	wp_trash_post( $changeset_id );

	cs_clear_preview_cookie();

	return array(
		'changeset_id'  => $changeset_id,
		'status'        => 'discarded',
		'deleted_count' => $deleted_count,
	);
}

/**
 * List changesets.
 *
 * @param array $args Optional. status, per_page, page.
 * @return array { items, total }
 */
function cs_list_changesets( $args = array() ) {
	$meta_status = array( 'open', 'approved' );
	if ( isset( $args['status'] ) ) {
		$meta_status = (array) $args['status'];
	}

	$query_args = array(
		'post_type'      => 'changeset',
		'post_status'    => 'any',
		'posts_per_page' => isset( $args['per_page'] ) ? (int) $args['per_page'] : 20,
		'paged'          => isset( $args['page'] ) ? (int) $args['page'] : 1,
		'meta_query'     => array(
			array(
				'key'     => '_changeset_status',
				'value'   => $meta_status,
				'compare' => 'IN',
			),
		),
		'orderby'        => 'date',
		'order'          => 'DESC',
	);

	$q = new WP_Query( $query_args );
	$items = array();
	foreach ( $q->posts as $post ) {
		$uuid         = cs_get_changeset_uuid( $post->ID );
		$status       = cs_get_changeset_status( $post->ID );
		$staged_count = count( cs_get_staged_drafts( $post->ID ) );

		$items[] = array(
			'changeset_id' => (int) $post->ID,
			'uuid'         => $uuid,
			'title'        => $post->post_title,
			'status'       => $status,
			'staged_count' => $staged_count,
			'created_gmt'  => $post->post_date_gmt,
		);
	}

	return array(
		'items' => $items,
		'total' => (int) $q->found_posts,
	);
}

/**
 * Hard-block public publish of staged drafts.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post object.
 */
function cs_prevent_staged_publish( $new_status, $old_status, $post ) {
	global $cs_publishing_changeset;

	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}
	if ( ! cs_is_staged( $post->ID ) ) {
		return;
	}

	// Allow publish during cs_publish_changeset internal workflow.
	if ( ! empty( $cs_publishing_changeset ) ) {
		return;
	}

	remove_action( 'transition_post_status', 'cs_prevent_staged_publish', 10 );
	wp_update_post(
		array(
			'ID'          => $post->ID,
			'post_status' => 'draft',
		)
	);
	add_action( 'transition_post_status', 'cs_prevent_staged_publish', 10, 3 );
}
add_action( 'transition_post_status', 'cs_prevent_staged_publish', 10, 3 );

/**
 * Get preview URL for a changeset.
 *
 * @param int $changeset_id Changeset ID.
 * @return string
 */
function cs_get_preview_url( $changeset_id ) {
	$uuid = cs_get_changeset_uuid( $changeset_id );
	return add_query_arg( 'changeset', $uuid, home_url( '/' ) );
}

/**
 * Set preview cookie.
 *
 * Session cookie (expires on browser close) with httponly for security.
 * Set at site root (/) to work across all pages.
 *
 * @param string $uuid Changeset UUID.
 */
function cs_set_preview_cookie( $uuid ) {
	if ( headers_sent() ) {
		return;
	}
	setcookie( 'changeset', $uuid, 0, '/', '', is_ssl(), true );
	$_COOKIE['changeset'] = $uuid;
}

/**
 * Clear preview cookie.
 *
 * Expires the cookie by setting it to empty with a past timestamp.
 */
function cs_clear_preview_cookie() {
	unset( $_COOKIE['changeset'] );
	if ( headers_sent() ) {
		return;
	}
	setcookie( 'changeset', '', time() - YEAR_IN_SECONDS, '/', '', is_ssl(), true );
}

/**
 * Get active preview changeset UUID from query or cookie.
 *
 * @return string|null
 */
function cs_get_active_preview_uuid() {
	$token = isset( $_GET['changeset'] ) ? $_GET['changeset'] : ( isset( $_COOKIE['changeset'] ) ? $_COOKIE['changeset'] : null );
	if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $token ) ) {
		return null;
	}
	if ( defined( 'CHANGESETS_PRIVATE_PREVIEWS' ) && CHANGESETS_PRIVATE_PREVIEWS && ! current_user_can( 'manage_changesets' ) ) {
		return null;
	}
	return $token;
}

/**
 * Initialize preview mode: set cookie from query param.
 */
function cs_init_preview() {
	// Exit first — clear cookie even if UUID only lived in the cookie.
	if ( isset( $_GET['cs_exit_preview'] ) ) {
		cs_clear_preview_cookie();
		wp_safe_redirect( remove_query_arg( array( 'cs_exit_preview', 'changeset' ) ) );
		exit;
	}

	$uuid = cs_get_active_preview_uuid();
	if ( ! $uuid ) {
		return;
	}

	$changeset = cs_get_preview_changeset( $uuid );
	if ( ! $changeset ) {
		cs_clear_preview_cookie();
		return;
	}

	// Private preview mode: require logged-in user with manage_changesets capability.
	if ( defined( 'CHANGESETS_PRIVATE_PREVIEWS' ) && CHANGESETS_PRIVATE_PREVIEWS ) {
		if ( ! is_user_logged_in() ) {
			cs_clear_preview_cookie();
			auth_redirect();
		}
		if ( ! current_user_can( 'manage_changesets' ) ) {
			cs_clear_preview_cookie();
			wp_die(
				esc_html__( 'You do not have permission to preview changesets.', 'changesets' ),
				esc_html__( 'Insufficient Permissions', 'changesets' ),
				array( 'response' => 403 )
			);
		}
	}

	if ( isset( $_GET['changeset'] ) ) {
		cs_set_preview_cookie( $uuid );
	}
}
add_action( 'init', 'cs_init_preview' );



/**
 * Load staged draft IDs for the active preview changeset once per request.
 *
 * Optimized with a single indexed query and static caching.
 *
 * @return array{changeset_id:int, map:array<int,int>, new_ids:array<int>}|null
 */
function cs_preview_staged_index() {
	static $index = null;
	static $loaded = false;

	if ( $loaded ) {
		return $index;
	}
	$loaded = true;

	$uuid = cs_get_active_preview_uuid();
	if ( ! $uuid ) {
		return null;
	}

	$changeset = cs_get_preview_changeset( $uuid );
	if ( ! $changeset ) {
		return null;
	}

	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS source_id
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_status = 'draft'
			AND pm.meta_key IN ('_changeset_is_staged', '_changeset_id', %s)
			AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm2
				WHERE pm2.post_id = p.ID
				AND pm2.meta_key = '_changeset_is_staged'
				AND pm2.meta_value = '1'
			)
			AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm3
				WHERE pm3.post_id = p.ID
				AND pm3.meta_key = '_changeset_id'
				AND pm3.meta_value = %d
			)
			GROUP BY p.ID",
			CS_META_SOURCE,
			CS_META_SOURCE,
			$changeset->ID
		)
	);

	$map     = array();
	$new_ids = array();
	foreach ( $rows as $row ) {
		$staged_id = (int) $row->ID;
		$source_id = (int) $row->source_id;
		if ( $source_id > 0 ) {
			$map[ $source_id ] = $staged_id;
		} else {
			$new_ids[] = $staged_id;
		}
	}

	$index = array(
		'changeset_id' => (int) $changeset->ID,
		'map'          => $map,
		'new_ids'      => $new_ids,
	);
	return $index;
}

/**
 * Overlay staged content in WP_Query results during preview.
 *
 * Efficiently swaps live posts with their staged versions using pre-loaded index.
 *
 * @param array    $posts Posts.
 * @param WP_Query $query Query.
 * @return array
 */
function cs_overlay_staged_content( $posts, $query ) {
	$index = cs_preview_staged_index();
	if ( ! $index || ! $posts ) {
		return $posts;
	}

	$map     = $index['map'];
	$new_ids = $index['new_ids'];

	// Swap live posts with staged versions.
	foreach ( $posts as $i => $post ) {
		if ( isset( $map[ (int) $post->ID ] ) ) {
			$staged = wp_cache_get( $map[ (int) $post->ID ], 'posts' );
			if ( ! $staged ) {
				$staged = get_post( $map[ (int) $post->ID ] );
			}
			if ( $staged ) {
				$overlay              = clone $staged;
				$overlay->ID          = $post->ID;
				$overlay->post_name   = $post->post_name;
				$overlay->post_status = 'publish';
				$posts[ $i ]          = $overlay;
			}
		}
	}

	// Inject brand-new staged items for relevant queries.
	if ( $new_ids ) {
		$post_type      = $query->get( 'post_type' );
		$query_types    = is_array( $post_type ) ? $post_type : ( $post_type ? array( $post_type ) : array() );
		$inject_any     = empty( $query_types );
		$existing       = array_flip( wp_list_pluck( $posts, 'ID' ) );

		foreach ( $new_ids as $staged_id ) {
			if ( isset( $existing[ $staged_id ] ) ) {
				continue;
			}
			$staged = wp_cache_get( $staged_id, 'posts' );
			if ( ! $staged ) {
				$staged = get_post( $staged_id );
			}
			if ( ! $staged ) {
				continue;
			}
			// Inject if query wants any type or specifically wants this type.
			if ( $inject_any || in_array( $staged->post_type, $query_types, true ) ) {
				$overlay              = clone $staged;
				$overlay->post_status = 'publish';
				$posts[]              = $overlay;
			}
		}
	}

	return $posts;
}

/**
 * @param WP_Query $query Query.
 */
function cs_preview_filter_posts( $query ) {
	static $added = false;
	if ( $added || ! cs_get_active_preview_uuid() ) {
		return;
	}
	if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return;
	}
	$added = true;
	add_filter( 'posts_results', 'cs_overlay_staged_content', 10, 2 );
}
add_action( 'pre_get_posts', 'cs_preview_filter_posts' );

/**
 * Dynamically apply preview filters for all staged options and theme_mods.
 *
 * Called once per request when a changeset is active. Reads the staged options bag
 * and registers filters for each key so preview is truthful.
 */
function cs_preview_init_dynamic_filters() {
	$uuid = cs_get_active_preview_uuid();
	if ( ! $uuid ) {
		return;
	}

	$changeset = cs_get_preview_changeset( $uuid );
	if ( ! $changeset ) {
		return;
	}

	$bag = cs_get_staged_options( $changeset->ID );
	if ( empty( $bag ) ) {
		return;
	}

	foreach ( $bag as $key => $item ) {
		$store = is_array( $item ) && isset( $item['store'] ) ? $item['store'] : 'option';
		if ( ! cs_is_safe_staged_option( $key, $store ) ) {
			continue;
		}
		// Backward compatibility: 0.5.0+ structure { value, store } vs 0.4.2 direct value.
		if ( is_array( $item ) && isset( $item['store'] ) && 'theme_mod' === $item['store'] ) {
			// Theme mod: hook pre_theme_mod_{$key}.
			add_filter( "pre_theme_mod_{$key}", 'cs_preview_filter_theme_mod', 10, 2 );
		} else {
			// Option: hook pre_option_{$key}.
			add_filter( "pre_option_{$key}", 'cs_preview_filter_option', 10, 2 );
		}
	}
}
add_action( 'init', 'cs_preview_init_dynamic_filters', 20 );

/**
 * Overlay staged options during preview.
 *
 * @param mixed  $pre    Short-circuit value.
 * @param string $option Option name.
 * @return mixed
 */
function cs_preview_filter_option( $pre, $option ) {
	if ( ! cs_is_safe_staged_option( $option ) ) {
		return $pre;
	}
	$index = cs_preview_staged_index();
	if ( ! $index ) {
		return $pre;
	}
	$bag = cs_get_staged_options( $index['changeset_id'] );
	if ( array_key_exists( $option, $bag ) ) {
		// 0.5.0+ structure: { value, store }.
		if ( is_array( $bag[ $option ] ) && isset( $bag[ $option ]['value'] ) ) {
			return $bag[ $option ]['value'];
		}
		// 0.4.2 backward compat: direct value.
		return $bag[ $option ];
	}
	return $pre;
}

/**
 * Overlay staged theme_mods during preview.
 *
 * @param mixed  $pre  Short-circuit value.
 * @param string $name Theme mod name.
 * @return mixed
 */
function cs_preview_filter_theme_mod( $pre, $name ) {
	$index = cs_preview_staged_index();
	if ( ! $index ) {
		return $pre;
	}
	$bag = cs_get_staged_options( $index['changeset_id'] );
	if ( array_key_exists( $name, $bag ) ) {
		$item = $bag[ $name ];
		// Check if this is stored as a theme_mod.
		if ( is_array( $item ) && isset( $item['store'] ) && 'theme_mod' === $item['store'] && isset( $item['value'] ) ) {
			return $item['value'];
		}
	}
	return $pre;
}

/**
 * Overlay staged style variation onto user theme.json during preview.
 *
 * @param WP_Theme_JSON_Data $theme_json Theme JSON data object.
 * @return WP_Theme_JSON_Data
 */
function cs_preview_global_styles( $theme_json ) {
	$uuid = cs_get_active_preview_uuid();
	if ( ! $uuid ) {
		return $theme_json;
	}
	$changeset = cs_get_preview_changeset( $uuid );
	if ( ! $changeset || ! class_exists( 'WP_Theme_JSON_Data' ) ) {
		return $theme_json;
	}

	$payload = cs_get_staged_global_styles( $changeset->ID );
	if ( ! $payload ) {
		// Back-compat: variation stem only.
		$stem = cs_get_staged_style_variation( $changeset->ID );
		if ( ! $stem ) {
			return $theme_json;
		}
		$resolved = cs_resolve_style_variation( $stem );
		if ( is_wp_error( $resolved ) ) {
			return $theme_json;
		}
		$payload = $resolved['data'];
	}

	$payload['isGlobalStylesUserThemeJSON'] = true;
	if ( empty( $payload['version'] ) ) {
		$payload['version'] = 3;
	}
	return new WP_Theme_JSON_Data( $payload, 'custom' );
}
add_filter( 'wp_theme_json_data_user', 'cs_preview_global_styles' );





/**
 * Inject new staged pages into get_pages for core/page-list (SQL-backed index, no nested get_pages).
 *
 * @param array $pages Pages.
 * @param array $args  Args.
 * @return array
 */
function cs_preview_filter_get_pages( $pages, $args ) {
	$index = cs_preview_staged_index();
	if ( ! $index || empty( $index['new_ids'] ) ) {
		return $pages;
	}

	$existing = array();
	foreach ( $pages as $p ) {
		$existing[ (int) $p->ID ] = true;
	}
	foreach ( $index['new_ids'] as $staged_id ) {
		if ( isset( $existing[ (int) $staged_id ] ) ) {
			continue;
		}
		$staged = get_post( $staged_id );
		if ( ! $staged || 'page' !== $staged->post_type ) {
			continue;
		}
		$overlay              = clone $staged;
		$overlay->post_status = 'publish';
		$pages[]              = $overlay;
	}
	return $pages;
}
add_filter( 'get_pages', 'cs_preview_filter_get_pages', 10, 2 );

/**
 * Resolve /slug/ to a source-less staged page while previewing.
 *
 * @param array $query_vars Query vars.
 * @return array
 */
function cs_preview_resolve_new_page( $query_vars ) {
	if ( empty( $query_vars['pagename'] ) ) {
		return $query_vars;
	}
	$index = cs_preview_staged_index();
	if ( ! $index || empty( $index['new_ids'] ) ) {
		return $query_vars;
	}
	$pagename = $query_vars['pagename'];
	foreach ( $index['new_ids'] as $staged_id ) {
		$staged = get_post( $staged_id );
		if ( $staged && 'page' === $staged->post_type && $staged->post_name === $pagename ) {
			unset( $query_vars['pagename'] );
			$query_vars['page_id']   = $staged_id;
			$query_vars['post_type'] = 'page';
			return $query_vars;
		}
	}
	return $query_vars;
}
add_filter( 'request', 'cs_preview_resolve_new_page' );

/**
 * Allow main query to load draft staged pages when preview resolves page_id to one.
 *
 * @param WP_Query $query Query.
 */
function cs_preview_allow_staged_status( $query ) {
	if ( ! $query->is_main_query() || ! cs_get_active_preview_uuid() ) {
		return;
	}
	$page_id = (int) $query->get( 'page_id' );
	if ( ! $page_id ) {
		return;
	}
	$index = cs_preview_staged_index();
	if ( $index && in_array( $page_id, $index['new_ids'], true ) ) {
		$query->set( 'post_status', array( 'publish', 'draft' ) );
	}
}
add_action( 'pre_get_posts', 'cs_preview_allow_staged_status', 20 );

/**
 * Hide staged drafts from the default Pages / Posts admin lists.
 *
 * @param WP_Query $query Query.
 */
function cs_hide_staged_from_admin_lists( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'edit-page', 'edit-post' ), true ) ) {
		return;
	}
	$meta_query = $query->get( 'meta_query' );
	if ( ! is_array( $meta_query ) ) {
		$meta_query = array();
	}
	$meta_query[] = array(
		'relation' => 'OR',
		array(
			'key'     => '_changeset_is_staged',
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => '_changeset_is_staged',
			'value'   => '1',
			'compare' => '!=',
		),
	);
	$query->set( 'meta_query', $meta_query );
}
add_action( 'pre_get_posts', 'cs_hide_staged_from_admin_lists' );

/**
 * Get Changesets plugin status and readiness check.
 *
 * @return array { version, abilities_registered, user_caps, open_changeset_count }
 */
function cs_get_status() {
	$status = array(
		'version'              => CS_VERSION,
		'abilities_registered' => function_exists( 'wp_register_ability' ),
		'user_caps'            => array(
			'manage_changesets'  => current_user_can( 'manage_changesets' ),
			'approve_changesets' => current_user_can( 'approve_changesets' ),
			'publish_changesets' => current_user_can( 'publish_changesets' ),
		),
		'open_changeset_count' => 0,
	);

	// Count open changesets.
	$open = get_posts(
		array(
			'post_type'      => 'changeset',
			'post_status'    => 'draft',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => '_changeset_status',
					'value' => 'open',
				),
			),
			'fields'         => 'ids',
		)
	);
	$status['open_changeset_count'] = count( $open );

	return $status;
}

/**
 * When a changeset is deleted, hard-delete its staged drafts.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post.
 */
function cs_delete_changeset_staged( $post_id, $post ) {
	if ( ! $post || 'changeset' !== $post->post_type ) {
		return;
	}
	foreach ( cs_get_staged_drafts( $post_id ) as $staged_id ) {
		wp_delete_post( $staged_id, true );
	}
}
add_action( 'before_delete_post', 'cs_delete_changeset_staged', 10, 2 );

/**
 * Whether the current user can approve a changeset.
 *
 * @param int $changeset_id Changeset ID.
 * @return bool
 */
function cs_user_can_approve_changeset( $changeset_id ) {
	return current_user_can( 'approve_changesets' ) && ( ! cs_get_staged_options( $changeset_id ) || current_user_can( 'manage_options' ) );
}

/**
 * Whether the current user can publish a changeset.
 *
 * @param int $changeset_id Changeset ID.
 * @return bool
 */
function cs_user_can_publish_changeset( $changeset_id ) {
	return current_user_can( 'publish_changesets' );
}

/** Whether a setting is safe to stage, preview, or publish. */
function cs_is_safe_staged_option( $key, $store = 'option' ) {
	// Denylist: unsafe keys that affect bootstrap, authentication, or core behavior.
	$denylisted_options = array(
		// Registration and authorization must never be previewed.
		'users_can_register',
		'default_role',
		'admin_email',
		'new_admin_email',
		// Plugin management.
		'active_plugins',
		'uninstall_plugins',
		// Theme switching.
		'template',
		'stylesheet',
		'current_theme',
		'theme_switched',
		// Site URLs (change request context).
		'siteurl',
		'home',
		// Permalinks and rewrites (change routing/bootstrap).
		'permalink_structure',
		'rewrite_rules',
		'category_base',
		'tag_base',
	);

	/**
	 * Filter the denylist of options that cannot be staged.
	 *
	 * @param array  $denylisted_options Unsafe option keys.
	 * @param string $key                The option key being staged.
	 * @param string $store              Storage type ('option' or 'theme_mod').
	 */
	$denylisted_options = apply_filters( 'cs_denylisted_options', $denylisted_options, $key, $store );

	return 'option' !== $store || ! in_array( $key, $denylisted_options, true );
}


/** Revoke approval whenever staged payloads change, including native editor saves. */
function cs_invalidate_approval( $changeset_id ) {
	global $cs_publishing_changeset;
	if ( $cs_publishing_changeset || ! cs_is_changeset_approved( $changeset_id ) ) {
		return;
	}
	update_post_meta( $changeset_id, '_changeset_status', 'open' );
	delete_post_meta( $changeset_id, '_changeset_approved_by' );
	delete_post_meta( $changeset_id, '_changeset_approved_at' );
}

function cs_invalidate_approval_on_meta( $meta_id, $post_id, $key, $value ) {
	if ( '_changeset_id' === $key ) {
		cs_invalidate_approval( (int) $value );
	}
	if ( in_array( $key, array( '_changeset_staged_options', '_changeset_staged_global_styles', '_changeset_staged_style_variation', '_changeset_staged_style_variation_title' ), true ) ) {
		cs_invalidate_approval( $post_id );
	} elseif ( '_changeset_is_staged' === $key || cs_is_staged( $post_id ) ) {
		cs_invalidate_approval( cs_get_staged_changeset_id( $post_id ) );
	}
}
add_action( 'added_post_meta', 'cs_invalidate_approval_on_meta', 10, 4 );
add_action( 'updated_post_meta', 'cs_invalidate_approval_on_meta', 10, 4 );
add_action( 'deleted_post_meta', 'cs_invalidate_approval_on_meta', 10, 4 );

function cs_invalidate_approval_on_post( $post_id, $post_after, $post_before ) {
	if ( cs_is_staged( $post_id ) && $post_after != $post_before ) {
		cs_invalidate_approval( cs_get_staged_changeset_id( $post_id ) );
	}
}
add_action( 'post_updated', 'cs_invalidate_approval_on_post', 10, 3 );

function cs_invalidate_approval_on_delete( $post_id ) {
	if ( cs_is_staged( $post_id ) ) {
		cs_invalidate_approval( cs_get_staged_changeset_id( $post_id ) );
	}
}
add_action( 'before_delete_post', 'cs_invalidate_approval_on_delete' );

/** Public preview resolution never accepts the internal numeric identifier. */
function cs_get_preview_changeset( $uuid ) {
	if ( ! is_string( $uuid ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $uuid ) ) {
		return null;
	}
	$changeset = cs_get_changeset( $uuid );
	if ( ! $changeset || ! in_array( cs_get_changeset_status( $changeset->ID ), array( 'open', 'approved' ), true ) ) {
		return null;
	}
	return $changeset;
}
