<?php
// Usage: php transform.php <php-transformer dir> <page.html> [html|artifact]
[$_, $root, $page] = $argv; $mode = $argv[3] ?? 'html';
spl_autoload_register(static function ($c) use ($root) {
    $p = 'Automattic\\BlocksEngine\\PhpTransformer\\';
    if (strncmp($c, $p, strlen($p)) === 0) { $f = "$root/src/" . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; if (is_file($f)) require $f; }
});
$html = file_get_contents($page);
if ($mode === 'artifact') {
    $r = (new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array('generated_html' => $html))->toArray();
} elseif ($mode === 'static') {
    $css = '';
    $html = preg_replace_callback('#<style[^>]*>(.*?)</style>#is', static function ($m) use (&$css) { $css .= $m[1]; return ''; }, $html);
    $r = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($html, array('source' => 'proof', 'static_css' => $css))->toArray();
} else {
    $r = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($html, array('source' => 'proof'))->toArray();
}
echo json_encode($r, JSON_UNESCAPED_SLASHES);
