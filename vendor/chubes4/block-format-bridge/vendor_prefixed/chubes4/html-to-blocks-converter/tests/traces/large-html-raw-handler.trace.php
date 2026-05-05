<?php

namespace BlockFormatBridge\Vendor;

/**
 * Homeboy trace: large repeated-card raw-handler conversion.
 *
 * @package HTMLToBlocksConverter
 */
if (!\defined('ABSPATH')) {
    \define('ABSPATH', __DIR__);
}
if (!\class_exists('WP_HTML_Processor', \false)) {
    $wp_html_api_candidates = \array_filter(array(\getenv('WP_HTML_API_PATH') ? \getenv('WP_HTML_API_PATH') : '', '/wordpress/wp-includes/html-api', '/Users/chubes/Studio/intelligence-chubes4/wp-includes/html-api'));
    $wp_html_api_path = '';
    foreach ($wp_html_api_candidates as $candidate) {
        if (\is_file(\rtrim($candidate, '/') . '/class-wp-html-processor.php')) {
            $wp_html_api_path = \rtrim($candidate, '/');
            break;
        }
    }
    if ('' === $wp_html_api_path) {
        \fwrite(\STDERR, "WP_HTML_Processor is unavailable. Set WP_HTML_API_PATH to wp-includes/html-api.\n");
        exit(1);
    }
    $core_root = \dirname($wp_html_api_path);
    if (\is_file($core_root . '/class-wp-token-map.php')) {
        require_once $core_root . '/class-wp-token-map.php';
    }
    if (\is_file($wp_html_api_path . '/html5-named-character-references.php')) {
        require_once $wp_html_api_path . '/html5-named-character-references.php';
    }
    foreach (array('class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-doctype-info.php', 'class-wp-html-unsupported-exception.php', 'class-wp-html-token.php', 'class-wp-html-tag-processor.php', 'class-wp-html-stack-event.php', 'class-wp-html-open-elements.php', 'class-wp-html-active-formatting-elements.php', 'class-wp-html-processor-state.php', 'class-wp-html-processor.php') as $file) {
        require_once $wp_html_api_path . '/' . $file;
    }
}
if (!\class_exists('WP_Block_Type_Registry', \false)) {
    class WP_Block_Type_Registry
    {
        public static function get_instance()
        {
            return new self();
        }
        public function is_registered($name)
        {
            return \in_array($name, array('core/button', 'core/buttons', 'core/group', 'core/heading', 'core/html', 'core/image', 'core/paragraph'), \true);
        }
        public function get_registered($name)
        {
            return (object) array('attributes' => array());
        }
    }
    \class_alias('BlockFormatBridge\Vendor\WP_Block_Type_Registry', 'WP_Block_Type_Registry', \false);
}
foreach (array('esc_attr', 'esc_html', 'esc_url') as $function_name) {
    if (!\function_exists($function_name)) {
        eval('function ' . $function_name . '( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, "UTF-8" ); }');
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\wp_strip_all_tags')) {
    function wp_strip_all_tags($text)
    {
        return \strip_tags((string) $text);
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\get_shortcode_regex')) {
    function get_shortcode_regex()
    {
        return '(?!)';
    }
}
$metrics_events = array();
if (!\function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
        global $metrics_events;
        if ('html_to_blocks_convert_metrics' === $hook_name) {
            $metrics_events[] = $args[0] ?? array();
        }
    }
}
if (!\function_exists('has_action')) {
    function has_action($hook_name)
    {
        return 'html_to_blocks_convert_metrics' === $hook_name;
    }
}
if (!\function_exists('BlockFormatBridge\Vendor\serialize_blocks')) {
    function serialize_blocks(array $blocks): string
    {
        $output = '';
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? '';
            $attrs = \array_diff_key($block['attrs'] ?? array(), array('content' => \true, 'text' => \true));
            $attrs_json = empty($attrs) ? '' : ' ' . \json_encode($attrs, \JSON_UNESCAPED_SLASHES);
            $output .= '<!-- wp:' . \substr($name, 5) . $attrs_json . ' -->';
            $output .= $block['innerHTML'] ?? '';
            $output .= serialize_blocks($block['innerBlocks'] ?? array());
            $output .= '<!-- /wp:' . \substr($name, 5) . ' -->';
        }
        return $output;
    }
}
$repo_root = \getenv('HOMEBOY_TRACE_COMPONENT_PATH') ?: \dirname(__DIR__, 2);
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-attribute-parser.php';
require_once $repo_root . '/includes/class-html-element.php';
require_once $repo_root . '/includes/class-transform-registry.php';
require_once $repo_root . '/raw-handler.php';
$results_file = \getenv('HOMEBOY_TRACE_RESULTS_FILE');
if (!\is_string($results_file) || '' === $results_file) {
    \fwrite(\STDERR, "HOMEBOY_TRACE_RESULTS_FILE is required\n");
    exit(2);
}
$target_bytes = (int) (\getenv('H2BC_TRACE_TARGET_BYTES') ?: 131072);
$html = html_to_blocks_trace_large_repeated_cards($target_bytes);
$started = \microtime(\true);
$blocks = html_to_blocks_raw_handler(array('HTML' => $html));
$total_ms = (\microtime(\true) - $started) * 1000;
$main_metrics = array();
$aggregate_transforms = array();
foreach ($metrics_events as $event) {
    foreach ((array) ($event['transforms'] ?? array()) as $name => $stats) {
        if (!isset($aggregate_transforms[$name])) {
            $aggregate_transforms[$name] = array('count' => 0, 'execute_ms' => 0.0);
        }
        $aggregate_transforms[$name]['count'] += (int) ($stats['count'] ?? 0);
        $aggregate_transforms[$name]['execute_ms'] += (float) ($stats['execute_ms'] ?? 0);
    }
    if (!isset($event['html_bytes']) || (int) $event['html_bytes'] < 100000) {
        continue;
    }
    $main_metrics = $event;
    break;
}
if (empty($main_metrics)) {
    $main_metrics = array('total_ms' => $total_ms);
}
\uasort($aggregate_transforms, static function (array $left, array $right): int {
    return $right['execute_ms'] <=> $left['execute_ms'];
});
$top_transform_name = (string) \array_key_first($aggregate_transforms);
$top_transform_stats = $top_transform_name ? $aggregate_transforms[$top_transform_name] : array('count' => 0, 'execute_ms' => 0.0);
$timeline = array(array('t_ms' => 0, 'source' => 'trace', 'event' => 'start', 'data' => array('html_bytes' => \strlen($html))));
$spans = array();
$cursor = 0;
foreach (array('extract_ms', 'element_parse_ms', 'transform_match_ms', 'transform_execute_ms', 'content_measure_ms') as $metric) {
    $duration = isset($main_metrics[$metric]) && \is_numeric($main_metrics[$metric]) ? (float) $main_metrics[$metric] : 0.0;
    $phase = \preg_replace('/_ms$/', '', $metric);
    $timeline[] = array('t_ms' => (int) \round($cursor), 'source' => 'h2bc', 'event' => $phase . '_start', 'data' => array('phase' => $phase));
    $cursor += $duration;
    $timeline[] = array('t_ms' => (int) \round($cursor), 'source' => 'h2bc', 'event' => $phase . '_end', 'data' => array('phase' => $phase, 'duration_ms' => $duration));
    $spans[] = array('id' => $phase, 'from' => 'h2bc.' . $phase . '_start', 'to' => 'h2bc.' . $phase . '_end');
}
$timeline[] = array('t_ms' => (int) \round($total_ms), 'source' => 'trace', 'event' => 'end', 'data' => array('blocks' => \count($blocks), 'total_ms' => $total_ms, 'html_bytes' => \strlen($html), 'top_transform' => $top_transform_name, 'top_transforms' => \array_slice($aggregate_transforms, 0, 8, \true), 'metrics' => $main_metrics));
\file_put_contents($results_file, \json_encode(array('component_id' => 'html-to-blocks-converter', 'scenario_id' => \getenv('HOMEBOY_TRACE_SCENARIO') ?: 'large-html-raw-handler', 'status' => 'pass', 'summary' => \sprintf('%d KB raw handler: %.1f ms total; transform execution %.1f ms; matching %.1f ms; top transform %s %.1f ms (%d calls)', (int) \round($target_bytes / 1024), $total_ms, (float) ($main_metrics['transform_execute_ms'] ?? 0), (float) ($main_metrics['transform_match_ms'] ?? 0), $top_transform_name, (float) ($top_transform_stats['execute_ms'] ?? 0), (int) ($top_transform_stats['count'] ?? 0)), 'timeline' => $timeline, 'span_definitions' => $spans, 'assertions' => array(), 'artifacts' => array()), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
function html_to_blocks_trace_large_repeated_cards(int $target_bytes): string
{
    $head = '<main><section class="bench-grid">';
    $tail = '</section></main>';
    $html = $head;
    $i = 0;
    while (\strlen($html) + \strlen($tail) < $target_bytes) {
        $i++;
        $html .= \sprintf('<article class="bench-card"><h2>Benchmark Card %1$d</h2><p>This static section exercises repeated card conversion through WordPress HTML APIs.</p><ul><li>Feature %1$d</li><li>Detail %1$d</li><li>Outcome %1$d</li></ul><p><a class="button" href="#card-%1$d">Read more</a></p></article>', $i);
    }
    return $html . $tail;
}
