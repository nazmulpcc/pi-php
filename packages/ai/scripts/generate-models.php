<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/src/ModelCatalog.php';

$contents = Pi\AI\ModelCatalog::render();
$target = dirname(__DIR__).'/src/models.generated.php';

file_put_contents($target, $contents.PHP_EOL);

fwrite(STDOUT, $target.PHP_EOL);
