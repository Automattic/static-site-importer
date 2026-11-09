<?php
// WP-CLI eval-file: compile with the candidate engine, then apply SSI's form
// bindings before counting. A raw runtime-semantics form is not the final output.
// phpcs:ignoreFile -- Standalone predicate, executed inside WordPress.
[$root, $candidate] = $args;
spl_autoload_register(static function ($class) use ($root) {
    $prefix = 'Automattic\\BlocksEngine\\PhpTransformer\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
    }
}, true, true);
// With PREDICATE_CAPTURE, compile the candidate as its route's document inside
// the capture, as the importer does: shared parts (<!--#include virtual -->),
// stylesheets, scripts and evidence resolve. Other routes' documents stay out.
$capture = getenv('PREDICATE_CAPTURE');
$route = trim((string) (json_decode((string) getenv('PREDICATE_TARGET'), true)['route'] ?? '/'), '/');
$document = 'website/' . ('' === $route ? '' : "$route/") . 'index.html';
$files = array(array('path' => $document, 'content' => file_get_contents($candidate)));
if ($capture && is_dir($capture)) {
    $skip = '#^(website/(?!parts/).*\.html?|reference/|semantic-evidence/|layout-geometry/|screenshots/|html/|html-mobile/|sections/|sections-mobile/|media/|resources/)#i';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($capture, FilesystemIterator::SKIP_DOTS)) as $file) {
        $rel = substr($file->getPathname(), strlen(rtrim($capture, '/')) + 1);
        if (preg_match('/\.(css|js|json|svg|html)$/i', $rel) && !preg_match($skip, $rel) && $rel !== $document) $files[] = array('path' => $rel, 'content' => file_get_contents($file->getPathname()));
    }
}
$result = (new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array('files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
if (empty($plan['pages'])) throw new RuntimeException('Compilation produced no pages');
$registry = Static_Site_Importer_Entity_Materializer_Registry::class;
$bindings = array();
foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    $entities = $registry::with_form_source_html($declaration['payload']['entities'], $plan);
    $entities = array_map(array($registry, 'prepare_form_entity'), $entities);
    $manifest = $registry::validate_forms_manifest(array('forms' => $entities));
    if (is_wp_error($manifest)) throw new RuntimeException($manifest->get_error_message());
    $report = Static_Site_Importer_Form_Seeder::seed($manifest);
    if (is_wp_error($report)) throw new RuntimeException($report->get_error_message());
    $rows = $registry::block_bindings(array('entities' => array('forms' => array(
        'adapter' => Static_Site_Importer_Form_Seeder::adapter(), 'manifest' => $manifest,
    ))), array('forms' => $report));
    if (is_wp_error($rows)) throw new RuntimeException($rows->get_error_message());
    $bindings = array_merge($bindings, $rows);
}
$count = 0;
$walk = static function (array $blocks) use (&$walk, &$count): void {
    foreach ($blocks as $block) {
        if ('core/html' === ($block['blockName'] ?? null)) ++$count;
        $walk($block['innerBlocks'] ?? array());
    }
};
foreach ($plan['pages'] ?? array() as $page) {
    $markup = $page['canonical_block_markup'] ?? '';
    foreach ($bindings as $binding) {
        $markup = str_replace($binding['search_block_markup'], $binding['replacement_block_markup'], $markup);
    }
    if (getenv('PREDICATE_DEBUG')) fwrite(STDERR, $markup . "\n");
    $walk(parse_blocks($markup));
}
echo json_encode($count);
