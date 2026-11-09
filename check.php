<?php
$ok=true;foreach(['curl','pdo_sqlite','session'] as $extension)if(!extension_loaded($extension)){echo "Missing PHP extension: $extension\n";$ok=false;}if(PHP_VERSION_ID<80200){echo "PHP 8.2+ required\n";$ok=false;}if(!is_writable(__DIR__.'/data')){echo "data directory must be writable\n";$ok=false;}exit($ok?0:1);
