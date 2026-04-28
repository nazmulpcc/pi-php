<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\Agent\Content\TextContent;
use Pi\CodingAgent\Session\SessionManager;

final class SessionHtmlExporter
{
    public function export(SessionManager $manager, string $outputPath): string
    {
        $context = $manager->buildSessionContext();
        $summary = (new SessionInspector)->summarize($manager);
        $rows = [];

        foreach ($context['messages'] as $message) {
            $parts = [];
            foreach ($message->content as $content) {
                if ($content instanceof TextContent) {
                    $parts[] = $content->text;
                }
            }

            if ($parts === []) {
                continue;
            }

            $rows[] = sprintf(
                '<article class="message"><h2>%s</h2><pre>%s</pre></article>',
                htmlspecialchars((string) $message->getRole()->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars(implode("\n", $parts), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        $html = sprintf(
            "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>Pi Session %s</title>\n<style>body{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#f7f5ef;color:#1c1b18;margin:0;padding:2rem;line-height:1.5}main{max-width:960px;margin:0 auto}header{margin-bottom:2rem;padding-bottom:1rem;border-bottom:1px solid #d7d0c0}pre{white-space:pre-wrap;background:#fff;padding:1rem;border:1px solid #d7d0c0;border-radius:8px}article{margin-bottom:1.5rem}dl{display:grid;grid-template-columns:max-content 1fr;gap:.35rem 1rem}</style>\n</head>\n<body>\n<main>\n<header><h1>Session %s</h1><dl><dt>Path</dt><dd>%s</dd><dt>Cwd</dt><dd>%s</dd><dt>Model</dt><dd>%s</dd><dt>Thinking</dt><dd>%s</dd><dt>Created</dt><dd>%s</dd></dl></header>\n%s\n</main>\n</body>\n</html>\n",
            htmlspecialchars($summary['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($summary['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) $summary['path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) $summary['cwd'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($summary['model'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) $summary['thinkingLevel'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($summary['createdAt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            implode("\n", $rows),
        );

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($outputPath, $html);

        return $outputPath;
    }
}
