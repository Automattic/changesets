# Changesets

Accumulate site edits in a staging Changeset, preview them on the live site without touching production, then Publish Changeset after human approval.

## For agents

**Agent prompt** (paste-ready):

> You have access to a WordPress site. Before staging changes: (1) Check if Changesets and WordPress MCP Adapter are installed and active by verifying `changesets/*` abilities are available via MCP. (2) If missing, explain both plugins are required, ask permission to install, then install if allowed. (3) Once plugins are active, ensure MCP connection with Application Password. Then workflow: create changeset → stage changes with changesets/save (type: content/styles/setting) → give human the preview URL and wait for approval → after approval call changesets/approve then changesets/publish. Never edit live content directly unless human explicitly requests a repair outside Changesets.

**Setup checklist** (verify BEFORE asking for credentials):
1. Are Changesets + MCP Adapter installed and active?
2. Verify by checking if `changesets/*` abilities are available via MCP tools
3. If missing: Ask permission to install, then install if allowed
4. Only after plugins are active: Request Application Password / MCP connection if not connected

**Why**: Site URL alone ≠ MCP access. You need the adapter plugin + authenticated connection for `changesets/*` tools to appear.

**Critical rule**: Never edit live posts, options, or styles directly. Use Changesets abilities only. Only bypass Changesets if human explicitly asks for a live repair.

**Workflow**:
1. **Create a changeset**: `changesets/create` → returns `{ preview_url, uuid, changeset_id }`
2. **Stage work**: `changesets/save` with one of three `type` values:
   - `content` — page/post/template/template-part/navigation/CPT
   - `styles` — global styles and style variations
   - `setting` — site options (blogname, page_on_front, etc.)
3. **Preview**: Give the human the `?changeset=<uuid>` URL and **wait for approval**
4. **Publish**: After approval, call `changesets/approve`, then `changesets/publish`

**What stages**: Content (pages, posts, templates, template parts, navigation, custom post types), global styles, style variations, and settings (site title, homepage, site logo, site icon, featured images).

**Media policy**: Attachment posts are never staged in changesets. Uploads go directly to the Media Library and persist even if the changeset is discarded. Changesets stage only references: featured images (`featured_media`), site logo (`custom_logo`), site icon (`site_icon`), and content HTML/blocks containing attachment IDs.

**Settings**: Site options and theme_mods are staged via `type=setting`. The plugin auto-detects storage type (option vs theme_mod) for known keys like `custom_logo` (theme_mod) and `site_icon` (option).

See [readme.txt](readme.txt) for complete documentation.

## Setup

**Requirements**: Hosted WordPress 6.9+ (not Playground)

1. Install and activate **Changesets** and **WordPress MCP Adapter** on your WordPress site
2. Create an Application Password (propose-only user without publish permissions recommended)
3. Connect your MCP client to the site using the Application Password

**Private preview mode** (optional): Require logged-in users with `manage_changesets` capability to preview changesets. Add to `wp-config.php`:

```php
define( 'CHANGESETS_PRIVATE_PREVIEWS', true );
```

When enabled, only logged-in users with the appropriate capability can use `?changeset=<uuid>` preview URLs. When disabled (default), anyone with the UUID can preview.

## License

GPLv2 or later

## Security boundaries

Propose-only users can create and edit their own changesets. Users with
`manage_changesets` can collaborate across sessions. Approval and publication
require their dedicated Changesets capabilities; staging, approving and publishing
settings also require `manage_options`.

Public preview URLs are UUID bearer links: anyone holding a valid link can see
that changeset unless private preview mode is enabled. UUIDs cannot be replaced
with numeric database IDs. Preview overlays are limited to presentation settings;
unknown plugin options remain administrative staging/publication work and are not
applied to public requests. Trusted site code can extend the presentation list
through `cs_previewable_options` after checking the setting's effects.

Option names must use printable ASCII without surrounding whitespace. Security,
registration, role-definition, bootstrap and internal publication-lock options
cannot be staged. Global styles are sanitized, and custom CSS proposals require
`edit_css` and cannot contain markup. Safe visual style proposals remain available.

Approval covers a fingerprint of the payload. Existing approvals created before
this protection require fresh approval. Publication captures and applies the
approved snapshot and locks the changeset against simultaneous external edits.
Locks are released on normal completion and exceptions. If a PHP worker is killed
abruptly, an administrator must first verify that publication has stopped, then
remove the orphan `_changeset_publish_lock_<ID>` option via WP-CLI before retrying;
do not remove a lock from an active publication.

See [local security tests](tests/README.md) for validation coverage and commands.
