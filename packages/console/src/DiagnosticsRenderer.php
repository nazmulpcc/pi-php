<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\Diagnostics\Diagnostic;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

final class DiagnosticsRenderer
{
    /**
     * @param  array<Diagnostic>  $diagnostics
     */
    public function render(OutputInterface $output, array $diagnostics, string $title = 'Diagnostics'): void
    {
        if ($diagnostics === []) {
            return;
        }

        $output->writeln('');
        $output->writeln($title.':');

        $table = new Table($output);
        $table->setHeaders(['Source', 'Scope', 'Severity', 'Path', 'Message']);
        foreach ($diagnostics as $diagnostic) {
            $table->addRow([
                $diagnostic->source,
                $diagnostic->scope ?? '',
                $diagnostic->severity,
                $diagnostic->path ?? '',
                $diagnostic->message,
            ]);
        }

        $table->render();
    }
}
