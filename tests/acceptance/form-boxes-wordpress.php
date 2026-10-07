<?php
/** Real WordPress/Jetpack producer-to-consumer box ownership acceptance. */
// phpcs:ignoreFile -- Runs only in the explicitly disposable Codebox workload.

if (!defined('SSI_FORM_BOXES_DISPOSABLE') || !SSI_FORM_BOXES_DISPOSABLE) throw new RuntimeException('A disposable WordPress runtime is required.');
$compiler = getenv('SSI_FORM_BOXES_COMPILER') ?: '/wordpress/wp-content/plugins/owning-compiler';
$root = ABSPATH . 'wp-content/plugins/static-site-importer';
require_once $compiler . '/php-transformer.php';
$autoload = require $root . '/vendor/autoload.php';
$autoload->setPsr4('Automattic\\BlocksEngine\\PhpTransformer\\', $compiler . '/src');
if (!str_starts_with((new ReflectionClass(Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\NativeGetFormBlockBuilder::class))->getFileName(), $compiler . '/src/')) throw new RuntimeException('Paired test did not load the declared owning compiler.');
require_once ABSPATH . 'wp-content/plugins/jetpack/jetpack.php';
require_once $root . '/includes/class-static-site-importer-form-seeder.php';
require_once $root . '/includes/class-static-site-importer-entity-materializer-registry.php';
Static_Site_Importer_Form_Seeder::register_runtime_bootstrap();
Static_Site_Importer_Provider_Form_Runtime_V1::register();
$ready = Static_Site_Importer_Jetpack_Forms_Runtime::prepare_jetpack_forms_runtime();
if (is_wp_error($ready)) throw new RuntimeException($ready->get_error_message());
$fixture = require ABSPATH . 'form-acceptance/fixtures/form-box-ownership-source.php';
$results = array();
$mail = array();
add_filter('pre_wp_mail', static function ($result, array $attributes) use (&$mail) { $mail[] = $attributes; return true; }, 10, 2);
foreach ($fixture['forms'] as $name => $form_html) {
    $result = (new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => '<!doctype html><html><head><link rel="stylesheet" href="source.css"></head><body>' . $form_html . '</body></html>', 'source.css' => $fixture['css']),
    ))->toArray();
    $entities = array();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) if ('forms' === ($declaration['type'] ?? null)) $entities = array_merge($entities, $declaration['payload']['entities'] ?? array());
    $css = $fixture['css'];
    $markup = $result['serialized_blocks'];
    $row = null;
    if ($entities) {
        $validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest(array('forms' => $entities));
        if (is_wp_error($validated)) throw new RuntimeException($validated->get_error_message());
        $row = Static_Site_Importer_Form_Seeder::seed(array('forms' => $validated['forms']))['forms'][0];
        if (empty($row['runtime_mapped'])) throw new RuntimeException('Provider declined neutral ' . $name . ': ' . wp_json_encode(array('receipt' => $row['computed_layout_receipt']['losses'] ?? array(), 'overlay' => $row['provider_layout_overlay_css'] ?? array(), 'decision' => $row['mapping_decision'] ?? null)));
        $markup = $row['block_markup'];
        $css .= $row['provider_layout_overlay_css']['css'] ?? '';
    }
    $post_id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => $name, 'post_content' => $markup));
    $GLOBALS['post'] = get_post($post_id);
    setup_postdata($GLOBALS['post']);
    $roundtrip = serialize_blocks(parse_blocks($markup));
    if ($roundtrip !== $markup) throw new RuntimeException('Block parser/serializer changed the saved neutral ' . $name . ' form.');
    $rendered = do_blocks($markup);
    $core_buttons = array();
    $collect_buttons = static function (array $blocks) use (&$collect_buttons, &$core_buttons): void {
        foreach ($blocks as $block) {
            if ('core/button' === $block['blockName']) $core_buttons[] = serialize_block($block);
            $collect_buttons($block['innerBlocks']);
        }
    };
    $collect_buttons(parse_blocks($markup));
    $native_definitions = 'fragment' === $name && !$entities ? (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($form_html, array('static_css' => $fixture['css']))->toArray()['source_reports']['generated_blocks'] ?? array() : array();
    $submission = null;
    if ('newsletter' === $name) {
        $forms = Automattic\Jetpack\Forms\ContactForm\Contact_Form::$forms;
        $newsletter_instance = end($forms);
        $newsletter_post_id = $post_id;
        $tags = new WP_HTML_Tag_Processor($rendered);
        $email_name = '';
        while ($tags->next_tag('INPUT')) if ('email' === $tags->get_attribute('type')) { $email_name = (string) $tags->get_attribute('name'); break; }
        if ('' === $email_name) throw new RuntimeException('Rendered provider email field has no submission identity.');
    }
    $results[$name] = array('source' => $form_html, 'sourceCss' => $fixture['css'], 'markup' => $markup, 'html' => $rendered, 'css' => $css, 'provider' => null !== $row, 'coreButtons' => $core_buttons, 'nativeDefinitions' => $native_definitions, 'submission' => $submission, 'entity' => $entities[0] ?? null);
}
$before = get_posts(array('post_type' => 'feedback', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1));
$previous_post = $_POST;
$_POST = array('contact-form-id' => (string) $newsletter_post_id, $email_name => 'reader@example.test');
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
try { $submitted = $newsletter_instance->process_submission(); } finally { $_POST = $previous_post; $_SERVER['HTTP_ACCEPT'] = $accept; }
$after = get_posts(array('post_type' => 'feedback', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1));
if (is_wp_error($submitted) || count(array_diff($after, $before)) !== 1 || count($mail) !== 1 || !str_contains($mail[0]['message'], 'reader@example.test')) throw new RuntimeException('Real provider submission did not store feedback and notify with the submitted value: ' . wp_json_encode(is_wp_error($submitted) ? $submitted->get_error_message() : array('feedback' => $after, 'mail' => count($mail))));
$results['newsletter']['submission'] = array('feedback_created' => 1, 'notification_captured' => 1, 'result_type' => get_debug_type($submitted));
$provider_css = (string) file_get_contents(dirname((new ReflectionClass(Automattic\Jetpack\Forms\ContactForm\Contact_Form::class))->getFileName(), 3) . '/dist/contact-form/css/grunion.css');
echo wp_json_encode(array('wordpress' => get_bloginfo('version'), 'jetpack' => JETPACK__VERSION, 'providerCss' => $provider_css, 'forms' => $results), JSON_UNESCAPED_SLASHES);
