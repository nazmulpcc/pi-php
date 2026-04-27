<?php

declare(strict_types=1);

namespace Pi\AI;

final class ModelCatalog
{
    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function seed(): array
    {
        $path = dirname(__DIR__).'/src/models.generated.php';

        return require $path;
    }

    public static function render(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return 
PHP.var_export(self::seed(), true).";\n";
    }
}
