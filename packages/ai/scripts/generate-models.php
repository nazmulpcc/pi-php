<?php

declare(strict_types=1);

$tsPath = dirname(__DIR__).'/../../pi-mono/packages/ai/src/models.generated.ts';
$phpPath = dirname(__DIR__).'/src/models.generated.php';

if (! file_exists($tsPath)) {
    fwrite(STDERR, "TS model file not found: {$tsPath}\n");
    fwrite(STDERR, "Make sure pi-mono is cloned and up to date.\n");
    exit(1);
}

$ts = file_get_contents($tsPath);
if ($ts === false) {
    fwrite(STDERR, "Failed to read TS model file.\n");
    exit(1);
}

$ts = preg_replace('/^\s*import\s+.*?from\s+"[^"]+";\s*/m', '', $ts);
$ts = preg_replace('/^\s*export\s+const\s+MODELS\s*=\s*/m', '', $ts);
$ts = preg_replace('/\s+as\s+const\s*;?\s*$/', '', $ts);
$ts = preg_replace('/\s*satisfies\s+Model<"[^"]+">/', '', $ts);

function convertTsToPhp(string $input): string
{
    $out = '';
    $len = strlen($input);
    $i = 0;
    $inString = false;
    $stringChar = '';
    $escapeNext = false;

    while ($i < $len) {
        $ch = $input[$i];

        if ($escapeNext) {
            $out .= $ch;
            $escapeNext = false;
            $i++;

            continue;
        }

        if ($ch === '\\') {
            $out .= $ch;
            $escapeNext = true;
            $i++;

            continue;
        }

        if ($inString) {
            if ($ch === $stringChar) {
                $inString = false;
            }
            $out .= $ch;
            $i++;

            continue;
        }

        if ($ch === '"' || $ch === "'") {
            $inString = true;
            $stringChar = $ch;
            $out .= $ch;
            $i++;

            continue;
        }

        if ($ch === '/' && $i + 1 < $len && $input[$i + 1] === '/') {
            while ($i < $len && $input[$i] !== "\n") {
                $i++;
            }

            continue;
        }

        if ($ch === '/' && $i + 1 < $len && $input[$i + 1] === '*') {
            $i += 2;
            while ($i < $len - 1) {
                if ($input[$i] === '*' && $input[$i + 1] === '/') {
                    $i += 2;
                    break;
                }
                $i++;
            }

            continue;
        }

        if ($ch === '{') {
            $out .= '[';
            $i++;

            continue;
        }

        if ($ch === '}') {
            $out .= ']';
            $i++;

            continue;
        }

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*/', substr($input, $i), $matches) === 1) {
            $key = $matches[0];
            $afterKey = $i + strlen($key);
            if ($afterKey < $len && $input[$afterKey] === ':') {
                $out .= "'{$key}' => ";
                $i = $afterKey + 1;

                continue;
            }
        }

        $out .= $ch;
        $i++;
    }

    $out = preg_replace('/"([^"]+)"\s*:\s*/', '"$1" => ', $out);

    return $out;
}

$phpBody = convertTsToPhp($ts);

$evalCheck = "return {$phpBody};";
$result = @eval($evalCheck);
if ($result === false && error_get_last() !== null) {
    $err = error_get_last();
    fwrite(STDERR, 'Generated PHP is not valid. Error: '.($err['message'] ?? 'unknown')."\n");
    exit(1);
}

$php = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$phpBody};\n";

if (file_put_contents($phpPath, $php) === false) {
    fwrite(STDERR, "Failed to write PHP model file.\n");
    exit(1);
}

$providers = count($result);
$models = array_sum(array_map('count', $result));
echo "Generated: {$phpPath}\n";
echo "Providers: {$providers}\n";
echo "Models: {$models}\n";
