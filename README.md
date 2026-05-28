# Bolt Sync

Bolt Sync is a WordPress Multisite plugin that keeps posts synchronized across every site in your network. When you update a page on one site, Bolt Sync propagates the change (content, ACF fields, taxonomies, SEO meta, and images) to all linked posts automatically using async background jobs.

---

## Features

- **Block editor panel** — a clean sidebar panel on every post/page lets you link, unlink, and manage sync groups without leaving the editor
- **Async sync via Action Scheduler** — saves queue a background job rather than running inline, keeping the WordPress admin fast
- **Full content sync** — title, content, slug, status, featured image, ACF fields, taxonomy terms, and Yoast SEO meta
- **Post validation** — after each sync, a background validator confirms all linked posts match
- **Join / Leave / Delete group** — fine-grained control; leaving removes only your site's post from the group while others remain linked
- **Speculative path preview** — toggle a remote site ON and immediately see where the duplicate will be created, before saving
- **Sync status polling** — a live "Syncing…" indicator with countdown ETA while the background job runs
- **Admin Columns integration** — optional sync-status column in the post list (requires [Admin Columns Pro](https://www.admincolumns.com/))

---

## Requirements

| Requirement | Minimum version |
|---|---|
| PHP | 8.1 |
| WordPress | 6.1 |
| WordPress Multisite | required |
| [Action Scheduler](https://actionscheduler.org/) | 3.x (bundled with WooCommerce, or install standalone) |

> ACF and Yoast SEO are optional. Bolt Sync detects them at runtime and syncs their data when present.

---

## Installation

1. Upload the `bolt-sync` directory to `/wp-content/plugins/`.
2. From the **Network Admin → Plugins** screen, **Network Activate** the plugin.
3. That's it — no settings page required. The sidebar panel appears automatically on all public post types.

### Building assets

The compiled JS/CSS bundle is not included in the repository. After cloning:

```bash
cd bolt-sync
npm install
npm run build
```

### PHP dependencies

```bash
composer install
```

---

## How It Works

### Link groups

Bolt Sync stores *link groups* — a central record that maps one post per site. The data model is two custom database tables:

- `{prefix}bolt_sync_links` — one row per group (post type, active flag)
- `{prefix}bolt_sync_link_items` — one row per (group, site, post) membership

A post carries a single `bolt_sync_link_id` post-meta value pointing to its group.

### Sync flow

```
User saves post
    ↓
wp_after_insert_post / acf/save_post (priority 99)
    ↓
Core::sync() — validates lock, queues Action Scheduler job
    ↓
mnps_sync_post AS job runs asynchronously
    ↓
Core::sync_content() — iterates linked sites, calls wp_update_post / wp_insert_post,
  then syncs ACF fields, taxonomy terms, Yoast meta, featured image
    ↓
do_action('bolt_sync_after_sync') — clears validation cache, queues validation job
    ↓
bolt_sync_validate_post AS job — runs BoltSyncValidator, caches result permanently
```

### Sync polling

The block editor polls `GET /bolt-sync/v1/sync-status/{postId}` every few seconds after a save. Once the job completes it calls `handleRefresh()`, which re-fetches the full panel data including the fresh validation result.

---

## Usage

### Linking posts

1. Open any post or page in the block editor.
2. Find the **Bolt Sync Manager** panel in the right sidebar (under the document settings).
3. Toggle ON the sites you want to link. Sites with a matching post at the same path are detected automatically.
4. Click **Save link**. Bolt Sync creates the link group and queues a background sync.

### Joining an existing group

If a peer site already has its post in a sync group, toggling it shows "Part of an existing group — saving will join it." Clicking **Save link** joins your post into that group.

### Leaving or deleting a group

- **Leave group** — removes only this site's post from the group. The remaining sites stay linked.
- **Delete group** — removes the entire group from all sites. Posts are preserved; only the sync relationship is deleted.

### Changing which post is linked

Click **Change post** next to any remote site row to search and select a different existing post as the link target.

### Validation badge

After sync completes, a small badge appears to the left of the Leave group button:

- **All N posts in sync ✓** (green) — validation passed; all linked posts match
- **Validation issues on N site(s)** (amber, hover for details) — content, taxonomy, or meta drift detected

The cache is permanent and only cleared when a post in the group is saved, ensuring the badge never shows stale data.

---

## Developer API

### Filters

#### `bolt_sync_skip_sync`
Prevent a post from being synced.
```php
add_filter( 'bolt_sync_skip_sync', function( bool $skip, int $post_id, int $link_id ): bool {
    // Skip draft posts.
    return get_post_status( $post_id ) === 'draft';
}, 10, 3 );
```

#### `bolt_sync_sync_fields`
Control which field types are synced to a given target site.
```php
add_filter( 'bolt_sync_sync_fields', function( array $fields, int $post_id, int $target_blog_id ): array {
    // Disable SEO sync to site 3.
    if ( $target_blog_id === 3 ) {
        return array_diff( $fields, [ 'seo' ] );
    }
    return $fields;
}, 10, 3 );
// Default $fields: [ 'acf', 'taxonomy', 'seo', 'thumbnail' ]
```

#### `bolt_sync_enable_post_link_manager`
Control which post types show the Bolt Sync Manager panel.
```php
add_filter( 'bolt_sync_enable_post_link_manager', function( array $post_types ): array {
    return [ 'post', 'page', 'my_custom_type' ];
} );
```

#### `bolt_sync_sites`
Modify the list of network sites available for linking.
```php
add_filter( 'bolt_sync_sites', function( array $sites ): array {
    // Exclude a specific site.
    return array_filter( $sites, fn( $s ) => (int) $s->blog_id !== 5 );
} );
```

#### `bolt_sync_validator_excluded_acf_fields`
Exclude ACF field keys from the post validator.
```php
add_filter( 'bolt_sync_validator_excluded_acf_fields', function( array $excluded ): array {
    return array_merge( $excluded, [ 'internal_notes', 'last_editor' ] );
} );
```

#### `bolt_sync_rest_authorize_capability`
Override the capability required for write REST endpoints (default: `delete_sites` on Multisite).
```php
add_filter( 'bolt_sync_rest_authorize_capability', function( string $cap ): string {
    return 'edit_pages';
} );
```

### Actions

#### `bolt_sync_before_sync`
Fires just before a sync job is dispatched.
```php
add_action( 'bolt_sync_before_sync', function( int $post_id, int $link_id ): void {
    // Log or notify before sync.
}, 10, 2 );
```

#### `bolt_sync_after_sync`
Fires after an async sync job completes.
```php
add_action( 'bolt_sync_after_sync', function( int $post_id, int|false $link_id, bool|\WP_Error $result, int $source_blog_id ): void {
    // Post-sync notification, logging, etc.
}, 10, 4 );
```

---

## Database Tables

### `{prefix}bolt_sync_links`

| Column | Type | Description |
|---|---|---|
| `id` | bigint | Primary key |
| `post_type` | varchar | Post type of the group |
| `active` | tinyint | Whether the group is active |
| `created_at` | datetime | Creation timestamp |

### `{prefix}bolt_sync_link_items`

| Column | Type | Description |
|---|---|---|
| `id` | bigint | Primary key |
| `link_id` | bigint | FK → bolt_sync_links.id |
| `blog_id` | bigint | Network site ID |
| `post_id` | bigint | Post ID on that site |
| `active` | tinyint | Whether this item is active |

A `UNIQUE KEY` on `(link_id, blog_id)` ensures at most one post per site per group.

---

## License

GPL-2.0-or-later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
