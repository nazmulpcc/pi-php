<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\Agent\Content\ImageContent;
use Pi\CodingAgent\Tool\PathHelper;

final class FileArgumentProcessor
{
    /**
     * @param  list<string>  $fileArgs
     * @return array{text: string, images: list<ImageContent>}
     */
    public function process(array $fileArgs, string $cwd): array
    {
        $text = '';
        $images = [];

        foreach ($fileArgs as $fileArg) {
            $absolutePath = PathHelper::resolve($cwd, $fileArg);
            if (! is_file($absolutePath)) {
                throw new \RuntimeException(sprintf('File not found: %s', $absolutePath));
            }

            $size = filesize($absolutePath);
            if ($size === 0) {
                continue;
            }

            $mimeType = $this->detectImageMimeType($absolutePath);
            if ($mimeType !== null) {
                $images[] = new ImageContent(
                    data: base64_encode((string) file_get_contents($absolutePath)),
                    mimeType: $mimeType,
                );
                $text .= sprintf("<file name=\"%s\"></file>\n", $absolutePath);

                continue;
            }

            $contents = file_get_contents($absolutePath);
            if ($contents === false) {
                throw new \RuntimeException(sprintf('Could not read file: %s', $absolutePath));
            }

            $text .= sprintf("<file name=\"%s\">\n%s\n</file>\n", $absolutePath, $contents);
        }

        return ['text' => $text, 'images' => $images];
    }

    private function detectImageMimeType(string $path): ?string
    {
        $mimeType = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                if (is_string($detected)) {
                    $mimeType = $detected;
                }
                finfo_close($finfo);
            }
        }

        return match ($mimeType) {
            'image/png', 'image/jpeg', 'image/gif', 'image/webp' => $mimeType,
            default => null,
        };
    }
}
