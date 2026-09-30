<?php
// Disposable acceptance dependency overlay, mounted before ordinary plugins.
$loader = require '/var/www/html/wp-content/plugins/static-site-importer/vendor/autoload.php';
$loader->addPsr4( 'Automattic\\BlocksEngine\\PhpTransformer\\', '/candidate/php-transformer/src', true );
