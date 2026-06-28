# Companion plugin: PHP-only dynamic blocks

The companion-plugin scaffolder
(`includes/class-static-site-importer-companion-plugin.php`) generates a
standalone, theme-independent plugin that houses a site's generated custom
blocks. Since WordPress 7.0 the scaffolder emits **PHP-only dynamic blocks**: no
`block.json`, no `index.js`/`view.js` build artifact, and no JS build pipeline in
the generated plugin.

## Registration shape

Each generated block is registered in PHP from the main plugin file
(`ssi-<site>/ssi-<site>.php`). The scaffolder embeds a registration spec per
block and wires a `render_callback` at registration time:

```php
function ssi_<site>_block_specs() {
	return array(
		array(
			'name' => 'ssi-<site>/<block>',
			'dir'  => '<block>',
			'args' => array(
				'api_version' => 3,
				'title'       => 'Block Title',
				'category'    => 'design',
				'attributes'  => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
				),
				'supports'    => array( 'interactivity' => true ),
			),
		),
	);
}

function ssi_<site>_render_callback( $block_dir ) {
	return static function ( $attributes, $content, $block ) use ( $block_dir ) {
		$render = SSI_<SITE>_DIR . 'blocks/' . $block_dir . '/render.php';
		if ( ! is_readable( $render ) ) {
			return '';
		}
		ob_start();
		include $render;
		return (string) ob_get_clean();
	};
}

function ssi_<site>_register_blocks() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}
	foreach ( ssi_<site>_block_specs() as $spec ) {
		$args                    = $spec['args'];
		$args['render_callback'] = ssi_<site>_render_callback( (string) $spec['dir'] );
		register_block_type( (string) $spec['name'], $args );
	}
}
add_action( 'init', 'ssi_<site>_register_blocks' );
```

`register_block_type( $name, $args )` accepts any public `WP_Block_Type`
property. The scaffolder passes:

- `api_version` (defaults to `3`) — enables the current block wrapper API.
- `attributes` — PHP-declared attribute schema, parsed and validated server-side
  by `WP_Block_Type::prepare_attributes_for_render()`.
- `render_callback` — makes the block **dynamic** (server-rendered).
- editor metadata carried verbatim from the payload's `block_json` slot
  (`title`, `category`, `description`, `keywords`, `icon`, `supports`,
  `usesContext`/`providesContext` → `uses_context`/`provides_context`, …).

The only block file on disk is `blocks/<block>/render.php`. The render callback
includes it with `$attributes`, `$content`, and `$block` in scope, mirroring the
`block.json` `render` template contract — without needing a `block.json` file.

## Why dynamic / server-rendered avoids invalid blocks

A static block stores its `save()` markup in post content. When the registered
`save()` output no longer matches the stored markup (a plugin update, a markup
tweak, a missing build), the editor reports **"This block contains unexpected or
invalid content."** (see #227).

A **dynamic block has no `save()`** — its markup is produced fresh on every
render by the `render_callback`. There is no stored-vs-`save()` comparison to
fail, so a generated dynamic block **cannot** trigger the invalid-block error by
construction. This is the primary reason the scaffolder converts generated blocks
to PHP-only dynamic blocks: it removes an entire class of editor breakage from
generated sites.

## Interactivity via the Interactivity API

Component-local interactivity uses the **Interactivity API**: server-rendered
`data-wp-*` directives emitted directly in `render.php`, with
`'supports' => array( 'interactivity' => true )` in the block's args. Because the
directives are HTML attributes rendered on the server, the interactive behavior
ships without a bundled editor script and without a JS build step.

```php
<?php
// blocks/<block>/render.php
?>
<div
	data-wp-interactive="ssi-<site>"
	data-wp-context='{ "open": false }'
>
	<button data-wp-on--click="actions.toggle">Toggle</button>
	<p data-wp-bind--hidden="!context.open">Hello</p>
</div>
```

If a block needs an Interactivity API store module, ship it as a carried static
asset via the block payload's `assets` map (a hand-written module, not generated
build output) and enqueue it from the block. This keeps the generated plugin
free of any compile/bundle pipeline.

## What is preserved

The PHP-only conversion is scoped to the block's **editor representation**. It
does not change:

- **Per-site namespacing** — blocks are still `ssi-<site>/<name>`.
- **Install / activate** — the plugin installs to `plugins/` (or `mu-plugins/`
  with a root loader stub) and activates via the materializer, declaring its
  `companion_plugin` dependency.
- **Preserved island JS (#496)** — separately carried custom JS that rides the
  companion plugin (scoped, theme-independent), enqueued from the main file via
  a `render_block` filter when its owning block renders. This is unrelated to
  block build output and is left intact.
