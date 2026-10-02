<?php
/**
 * Abilities API registration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Permission helper: Can user manage changesets?
 *
 * @return bool
 */
function cs_user_can_manage_changesets() {
	return current_user_can( 'manage_changesets' ) || current_user_can( 'edit_posts' );
}

/**
 * Register ability category.
 */
function cs_register_ability_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category(
		'changesets',
		array(
			'label'       => __( 'Changesets', 'changesets' ),
			'description' => __( 'Accumulate site edits in a staging session, preview without touching live, then publish after approval.', 'changesets' ),
		)
	);
}
add_action( 'wp_abilities_api_categories_init', 'cs_register_ability_category' );

/**
 * Register Abilities.
 */
function cs_register_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'changesets/create',
		array(
			'label'               => __( 'Create changeset', 'changesets' ),
			'description'         => __( 'Create a new changeset staging session for site edits. Returns changeset_id, uuid, and preview_url. All staged edits accumulate in this session until Publish Changeset.', 'changesets' ),
			'category'            => 'changesets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'title' => array(
						'type'        => 'string',
						'description' => 'Human-readable title for this changeset (e.g. "Add Contact", "Home copy pass").',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'changeset_id' => array( 'type' => 'integer' ),
					'uuid'         => array( 'type' => 'string' ),
					'preview_url'  => array( 'type' => 'string' ),
					'status'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cs_ability_create_changeset',
			'permission_callback' => 'cs_ability_can_create_changeset',
			'meta'                => array(
				'show_in_rest' => true,
				'public'       => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'changesets/get',
		array(
			'label'               => __( 'Get changeset', 'changesets' ),
			'description'         => __( 'Get changeset details including all staged entity drafts. Returns changeset metadata and list of staged items.', 'changesets' ),
			'category'            => 'changesets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'changeset_id' => array(
						'type'        => 'integer',
						'description' => 'Changeset ID.',
						'minimum'     => 1,
					),
				),
				'required'             => array( 'changeset_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'changeset_id' => array( 'type' => 'integer' ),
					'uuid'         => array( 'type' => 'string' ),
					'title'        => array( 'type' => 'string' ),
					'status'       => array( 'type' => 'string' ),
					'preview_url'  => array( 'type' => 'string' ),
					'staged_items' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'cs_ability_get_changeset',
			'permission_callback' => 'cs_ability_can_get_changeset',
			'meta'                => array(
				'show_in_rest' => true,
				'public'       => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'changesets/list',
		array(
			'label'               => __( 'List changesets', 'changesets' ),
			'description'         => __( 'List open or approved changesets. Returns paginated list with metadata.', 'changesets' ),
			'category'            => 'changesets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status'   => array(
						'type' => 'string',
						'enum' => array( 'open', 'approved' ),
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items' => array( 'type' => 'array' ),
					'total' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cs_ability_list_changesets',
			'permission_callback' => 'cs_ability_can_list_changesets',
			'meta'                => array(
				'show_in_rest' => true,
				'public'       => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'changesets/save',
		array(
			'label'               => __( 'Save to changeset', 'changesets' ),
			'description'         => __( 'Stage content, styles, or settings into a changeset. Type "content": stage pages/posts/templates/parts/navigation (source_id to clone, or new with title). Type "styles": apply variation name or styles/settings theme.json patch. Type "setting": stage site option or theme_mod (key+value). Returns staged entity details or confirmation.', 'changesets' ),
			'category'            => 'changesets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'changeset_id' => array(
						'type'        => 'integer',
						'description' => 'Changeset ID.',
						'minimum'     => 1,
					),
					'type'         => array(
						'type'        => 'string',
						'enum'        => array( 'content', 'styles', 'setting' ),
						'description' => 'Type of entity to stage: content (pages/posts/templates/etc), styles (global styles/variations), or setting (site options/theme_mods).',
					),
					'post_type'    => array(
						'type'        => 'string',
						'description' => '[content] Post type to stage (page, post, wp_template, wp_template_part, wp_navigation, or other public CPT). Default: page.',
					),
					'source_id'    => array(
						'type'        => 'integer',
						'description' => '[content] ID of published source to clone/update in this changeset. Omit to create new.',
						'minimum'     => 1,
					),
					'title'        => array(
						'type'        => 'string',
						'description' => '[content] Title (required for new pages/posts; optional update).',
					),
					'content'      => array(
						'type'        => 'string',
						'description' => '[content] Content (block markup or HTML).',
					),
					'excerpt'      => array(
						'type'        => 'string',
						'description' => '[content] Excerpt.',
					),
					'slug'         => array(
						'type'        => 'string',
						'description' => '[content] Slug.',
					),
					'theme'        => array(
						'type'        => 'string',
						'description' => '[content, wp_template only] Theme slug (defaults to active).',
					),
					'featured_media' => array(
						'type'        => 'integer',
						'description' => '[content] Featured image attachment ID. Set to 0 to remove. Attachments remain live; only references are staged.',
						'minimum'     => 0,
					),
					'thumbnail_id' => array(
						'type'        => 'integer',
						'description' => '[content] Alias for featured_media.',
						'minimum'     => 0,
					),
					'variation'    => array(
						'type'        => 'string',
						'description' => '[styles] Style variation file stem or title to apply.',
					),
					'styles'       => array(
						'type'        => 'object',
						'description' => '[styles] Partial theme.json styles object to merge.',
					),
					'settings'     => array(
						'type'        => 'object',
						'description' => '[styles] Partial theme.json settings object to merge.',
					),
					'key'          => array(
						'type'        => 'string',
						'description' => '[setting] Option or theme_mod name to stage. Most options/theme_mods are stageable; denylisted keys (active_plugins, template, siteurl, permalink_structure, etc.) are rejected. Common examples: show_on_front, page_on_front, page_for_posts, blogname, blogdescription, site_icon, custom_logo, posts_per_page, comment_registration, etc.',
					),
					'value'        => array(
						'description' => '[setting] Option value. For media references (site_icon, custom_logo), use attachment ID.',
					),
					'store'        => array(
						'type'        => 'string',
						'enum'        => array( 'option', 'theme_mod' ),
						'description' => '[setting] Storage type: "option" (wp_options) or "theme_mod". Auto-detected for known keys (custom_logo is theme_mod).',
					),
				),
				'required'             => array( 'changeset_id', 'type' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'staged_id'      => array( 'type' => 'integer' ),
					'changeset_id'   => array( 'type' => 'integer' ),
					'type'           => array( 'type' => 'string' ),
					'source_id'      => array( 'type' => 'integer' ),
					'title'          => array( 'type' => 'string' ),
					'slug'           => array( 'type' => 'string' ),
					'edit_url'       => array( 'type' => 'string' ),
					'preview_path'   => array( 'type' => 'string' ),
					'staged'         => array( 'type' => 'boolean' ),
					'variation'      => array( 'type' => 'string' ),
					'key'            => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cs_ability_save',
			'permission_callback' => 'cs_ability_can_save',
			'meta'                => array(
				'show_in_rest' => true,
				'public'       => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'changesets/approve',
		array(
			'label'               => __( 'Approve changeset', 'changesets' ),
			'description'         => __( 'Human approval gate. Mark changeset as approved so an agent can Publish Changeset. Prefer this after human review.', 'changesets' ),
			'category'            => 'changesets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'changeset_id' => array(
						'type'        => 'integer',
						'description' => 'ID of the changeset to approve.',
						'minimum'     => 1,
					),
				),
				'required'             => array( 'changeset_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'changeset_id' => array( 'type' => 'integer' ),
					'approved'     => array( 'type' => 'boolean' ),
					'preview_url'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cs_ability_approve_changeset',
			'permission_callback' => 'cs_ability_can_approve_changeset',
			'meta'                => array(
				'show_in_rest' => true,
				'public'       => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'changesets/publish',
		array(
			'label'               => __( 'Publish changeset', 'changesets' ),
			'description'         => __( 'Apply all staged edits in the changeset to live content, save native revisions for undo, then close the changeset. Requires human approval first via changesets/approve. Fails with cs_not_approved otherwise.', 'changesets' ),
			'category'            => 'changesets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'changeset_id' => array(
						'type'        => 'integer',
						'description' => 'ID of the approved changeset to publish.',
						'minimum'     => 1,
					),
				),
				'required'             => array( 'changeset_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'applied_count' => array( 'type' => 'integer' ),
					'source_ids'    => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'cs_ability_publish_changeset',
			'permission_callback' => 'cs_ability_can_publish_changeset',
			'meta'                => array(
				'show_in_rest' => true,
				'public'       => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	wp_register_ability(
		'changesets/discard',
		array(
			'label'               => __( 'Discard changeset', 'changesets' ),
			'description'         => __( 'Trash an open changeset and delete all its staged drafts. Clears preview cookie. Cannot discard published changesets.', 'changesets' ),
			'category'            => 'changesets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'changeset_id' => array(
						'type'        => 'integer',
						'description' => 'ID of the changeset to discard.',
						'minimum'     => 1,
					),
				),
				'required'             => array( 'changeset_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'changeset_id'  => array( 'type' => 'integer' ),
					'status'        => array( 'type' => 'string' ),
					'deleted_count' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cs_ability_discard_changeset',
			'permission_callback' => 'cs_ability_can_discard_changeset',
			'meta'                => array(
				'show_in_rest' => true,
				'public'       => true,
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'changesets/status',
		array(
			'label'               => __( 'Get Changesets status', 'changesets' ),
			'description'         => __( 'Get plugin version, readiness check, current user capabilities, and open changeset count. Use this to verify setup before creating changesets.', 'changesets' ),
			'category'            => 'changesets',
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'version'              => array( 'type' => 'string' ),
					'abilities_registered' => array( 'type' => 'boolean' ),
					'user_caps'            => array( 'type' => 'object' ),
					'open_changeset_count' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cs_ability_get_status',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'public'       => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
				),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'cs_register_abilities' );


// ============================================
// Ability callbacks
// ============================================

function cs_ability_can_create_changeset( $input ) {
	return cs_user_can_manage_changesets();
}

function cs_ability_create_changeset( $input ) {
	$title = isset( $input['title'] ) ? $input['title'] : null;
	$changeset_id = cs_create_changeset( $title );
	if ( is_wp_error( $changeset_id ) ) {
		return $changeset_id;
	}

	return array(
		'changeset_id' => $changeset_id,
		'uuid'         => cs_get_changeset_uuid( $changeset_id ),
		'preview_url'  => cs_get_preview_url( $changeset_id ),
		'status'       => cs_get_changeset_status( $changeset_id ),
	);
}

function cs_ability_can_get_changeset( $input ) {
	return cs_user_can_manage_changesets();
}

function cs_ability_get_changeset( $input ) {
	$changeset_id = (int) $input['changeset_id'];
	$changeset    = cs_get_changeset( $changeset_id );
	if ( ! $changeset ) {
		return new WP_Error( 'cs_not_changeset', __( 'Not a changeset.', 'changesets' ) );
	}

	$staged_ids = cs_get_staged_drafts( $changeset_id );
	$staged_items = array();
	foreach ( $staged_ids as $staged_id ) {
		$post      = get_post( $staged_id );
		$source_id = cs_get_staged_source_id( $staged_id );
		$source    = get_post( $source_id );

		$staged_items[] = array(
			'staged_id'      => (int) $staged_id,
			'source_post_id' => $source_id,
			'source_title'   => $source ? $source->post_title : '',
			'title'          => $post ? $post->post_title : '',
			'post_type'      => $post ? $post->post_type : '',
			'edit_url'       => get_edit_post_link( $staged_id, 'raw' ),
		);
	}

	$staged_options = cs_get_staged_options( $changeset_id );
	$staged_styles = cs_get_staged_global_styles( $changeset_id );
	$style_variation = cs_get_staged_style_variation( $changeset_id );

	return array(
		'changeset_id'    => $changeset_id,
		'uuid'            => cs_get_changeset_uuid( $changeset_id ),
		'title'           => $changeset->post_title,
		'status'          => cs_get_changeset_status( $changeset_id ),
		'preview_url'     => cs_get_preview_url( $changeset_id ),
		'staged_items'    => $staged_items,
		'staged_options'  => $staged_options ? $staged_options : array(),
		'staged_styles'   => $staged_styles ? $staged_styles : null,
		'style_variation' => $style_variation ? $style_variation : null,
	);
}

function cs_ability_can_list_changesets( $input ) {
	return cs_user_can_manage_changesets();
}

function cs_ability_list_changesets( $input ) {
	$input = is_array( $input ) ? $input : array();
	return cs_list_changesets( $input );
}

function cs_ability_can_save( $input ) {
	$type      = isset( $input['type'] ) ? $input['type'] : '';
	$source_id = isset( $input['source_id'] ) ? (int) $input['source_id'] : 0;

	if ( 'setting' === $type && ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	if ( 'content' === $type && $source_id && ! current_user_can( 'edit_post', $source_id ) ) {
		return false;
	}

	return cs_user_can_manage_changesets();
}

/**
 * Save to changeset: unified ability for content, styles, and settings.
 *
 * @param array $input Input.
 * @return array|WP_Error
 */
function cs_ability_save( $input ) {
	$changeset_id = (int) $input['changeset_id'];
	$type         = isset( $input['type'] ) ? $input['type'] : '';

	$changeset = cs_get_changeset( $changeset_id );
	if ( ! $changeset ) {
		return new WP_Error( 'cs_invalid_changeset', __( 'Invalid changeset.', 'changesets' ) );
	}

	switch ( $type ) {
		case 'content':
			return cs_ability_save_content( $changeset_id, $input );
		case 'styles':
			return cs_ability_save_styles( $changeset_id, $input );
		case 'setting':
			return cs_ability_save_setting( $changeset_id, $input );
		default:
			return new WP_Error( 'cs_invalid_type', __( 'Invalid type. Must be content, styles, or setting.', 'changesets' ) );
	}
}

/**
 * Save content to changeset (pages, posts, templates, etc).
 *
 * @param int   $changeset_id Changeset ID.
 * @param array $input        Input.
 * @return array|WP_Error
 */
function cs_ability_save_content( $changeset_id, $input ) {
	$post_type = isset( $input['post_type'] ) ? $input['post_type'] : 'page';
	$source_id = isset( $input['source_id'] ) ? (int) $input['source_id'] : 0;

	// Validate post type.
	if ( ! cs_is_stageable_post_type( $post_type ) ) {
		return new WP_Error( 'cs_unsupported_type', sprintf( __( 'Post type "%s" is not stageable.', 'changesets' ), $post_type ) );
	}

	if ( $source_id > 0 ) {
		// Clone or update existing source.
		$existing_staged = cs_get_staged_draft_for_source( $changeset_id, $source_id );
		if ( $existing_staged ) {
			$staged_id = $existing_staged;
		} else {
			$staged_id = cs_stage_content( $changeset_id, $source_id, $post_type );
			if ( is_wp_error( $staged_id ) ) {
				return $staged_id;
			}
		}
	} else {
		// Create new entity.
		$title = isset( $input['title'] ) ? $input['title'] : '';

		// For pages/posts, title is required.
		if ( in_array( $post_type, array( 'page', 'post' ), true ) && '' === trim( $title ) ) {
			return new WP_Error( 'cs_missing_title', __( 'Title is required when creating new content.', 'changesets' ) );
		}

		$content = isset( $input['content'] ) ? $input['content'] : '';
		$slug    = isset( $input['slug'] ) ? $input['slug'] : '';
		$theme   = isset( $input['theme'] ) ? $input['theme'] : '';

		$staged_id = cs_create_staged_content( $changeset_id, $post_type, $title, $content, $slug, $theme );
		if ( is_wp_error( $staged_id ) ) {
			return $staged_id;
		}
	}

	// Apply updates if provided.
	$fields = array();
	foreach ( array( 'title', 'content', 'excerpt' ) as $key ) {
		if ( array_key_exists( $key, $input ) && '' !== $input[ $key ] ) {
			$fields[ $key ] = $input[ $key ];
		}
	}
	if ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
		$fields['slug'] = $input['slug'];
	}

	if ( ! empty( $fields ) ) {
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
		if ( isset( $fields['slug'] ) ) {
			$update['post_name'] = sanitize_title( $fields['slug'] );
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	// Handle featured_media / thumbnail_id.
	if ( isset( $input['featured_media'] ) || isset( $input['thumbnail_id'] ) ) {
		$featured_id = isset( $input['featured_media'] ) ? (int) $input['featured_media'] : (int) $input['thumbnail_id'];
		if ( $featured_id > 0 ) {
			set_post_thumbnail( $staged_id, $featured_id );
		} else {
			delete_post_thumbnail( $staged_id );
		}
	}

	$staged = get_post( $staged_id );
	$uuid   = cs_get_changeset_uuid( $changeset_id );

	$preview_path = '';
	if ( 'page' === $post_type || 'post' === $post_type ) {
		$preview_path = '/' . $staged->post_name . '/?changeset=' . $uuid;
	}

	return array(
		'staged_id'    => $staged_id,
		'changeset_id' => $changeset_id,
		'type'         => 'content',
		'source_id'    => $source_id,
		'post_type'    => $post_type,
		'title'        => $staged->post_title,
		'slug'         => $staged->post_name,
		'edit_url'     => get_edit_post_link( $staged_id, 'raw' ),
		'preview_path' => $preview_path,
	);
}

/**
 * Save styles to changeset (global styles or style variation).
 *
 * @param int   $changeset_id Changeset ID.
 * @param array $input        Input.
 * @return array|WP_Error
 */
function cs_ability_save_styles( $changeset_id, $input ) {
	// Style variation.
	if ( isset( $input['variation'] ) && $input['variation'] ) {
		$result = cs_stage_style_variation( $changeset_id, $input['variation'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array_merge(
			$result,
			array(
				'changeset_id' => $changeset_id,
				'type'         => 'styles',
			)
		);
	}

	// Global styles patch.
	$patch = array();
	if ( isset( $input['styles'] ) && is_array( $input['styles'] ) ) {
		$patch['styles'] = $input['styles'];
	}
	if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
		$patch['settings'] = $input['settings'];
	}

	if ( empty( $patch ) ) {
		return new WP_Error( 'cs_missing_styles', __( 'Either variation, styles, or settings is required.', 'changesets' ) );
	}

	$result = cs_stage_global_styles( $changeset_id, $patch );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array_merge(
		$result,
		array(
			'changeset_id' => $changeset_id,
			'type'         => 'styles',
		)
	);
}

/**
 * Save setting to changeset.
 *
 * @param int   $changeset_id Changeset ID.
 * @param array $input        Input.
 * @return array|WP_Error
 */
function cs_ability_save_setting( $changeset_id, $input ) {
	$key   = isset( $input['key'] ) ? $input['key'] : '';
	$value = isset( $input['value'] ) ? $input['value'] : null;
	$store = isset( $input['store'] ) ? $input['store'] : 'option';

	if ( '' === $key ) {
		return new WP_Error( 'cs_missing_key', __( 'Setting key is required.', 'changesets' ) );
	}

	if ( null === $value ) {
		return new WP_Error( 'cs_missing_value', __( 'Setting value is required.', 'changesets' ) );
	}

	$result = cs_stage_option( $changeset_id, $key, $value, $store );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'changeset_id' => $changeset_id,
		'type'         => 'setting',
		'key'          => $key,
		'value'        => $value,
		'store'        => $store,
		'staged'       => true,
	);
}

function cs_ability_can_approve_changeset( $input ) {
	$changeset_id = isset( $input['changeset_id'] ) ? (int) $input['changeset_id'] : 0;
	return $changeset_id && cs_user_can_approve_changeset( $changeset_id );
}

function cs_ability_approve_changeset( $input ) {
	$changeset_id = (int) $input['changeset_id'];
	$result       = cs_approve_changeset( $changeset_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'changeset_id' => $changeset_id,
		'approved'     => true,
		'preview_url'  => cs_get_preview_url( $changeset_id ),
	);
}

function cs_ability_can_publish_changeset( $input ) {
	$changeset_id = isset( $input['changeset_id'] ) ? (int) $input['changeset_id'] : 0;
	return $changeset_id && cs_user_can_publish_changeset( $changeset_id );
}

function cs_ability_publish_changeset( $input ) {
	$changeset_id = (int) $input['changeset_id'];
	if ( ! cs_is_changeset_approved( $changeset_id ) ) {
		return new WP_Error(
			'cs_not_approved',
			__( 'A human must Approve Changeset before Publish Changeset.', 'changesets' ),
			array( 'changeset_id' => $changeset_id, 'preview_url' => cs_get_preview_url( $changeset_id ) )
		);
	}

	$result = cs_publish_changeset( $changeset_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $result;
}

function cs_ability_can_discard_changeset( $input ) {
	return cs_user_can_manage_changesets();
}

function cs_ability_discard_changeset( $input ) {
	$changeset_id = (int) $input['changeset_id'];
	$result       = cs_discard_changeset( $changeset_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $result;
}

function cs_ability_get_status( $input ) {
	return cs_get_status();
}
