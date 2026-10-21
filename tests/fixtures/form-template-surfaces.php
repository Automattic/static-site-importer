<?php
/**
 * Archive and absorbed pagination surfaces from issue #1890.
 *
 * @package Static_Site_Importer
 */

return array(
	'archive'    => array(
		'group'       => 'templates',
		'source_path' => 'category/business/index.html',
		'variant'     => 'category/business/page/2/index.html',
		'slug'        => 'category-business',
		'target'      => 'templates/category-business.html',
	),
	'part'       => array(
		'group'       => 'template_parts',
		'source_path' => 'shared/newsletter#footer',
		'variant'     => 'about/index.html',
		'slug'        => 'newsletter',
		'target'      => 'parts/newsletter.html',
	),
	'pagination' => array(
		'group'       => 'pages',
		'source_path' => 'blog/index.html',
		'variant'     => 'blog/page/2/index.html',
		'slug'        => 'home',
	),
);
