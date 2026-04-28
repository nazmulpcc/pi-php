<?php

declare(strict_types=1);

namespace Pi\Console;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class AsyncManualCodeReader
{
    /**
     * @return PromiseInterface<string>
     */
    public function read(OutputInterface $output, string $prompt = 'Paste the authorization code or redirect URL and press Enter: '): PromiseInterface
    {
        $deferred = new Deferred;

        if (! defined('STDIN') || ! is_resource(STDIN)) {
            $deferred->resolve('');

            return $deferred->promise();
        }

        $output->writeln('');
        $output->write($prompt);

        stream_set_blocking(STDIN, false);
        $buffer = '';

        Loop::addReadStream(STDIN, function ($stream) use (&$buffer, $deferred): void {
            $chunk = fgets($stream);
            if (! is_string($chunk)) {
                return;
            }

            $buffer .= $chunk;
            if (! str_contains($buffer, "\n")) {
                return;
            }

            Loop::removeReadStream($stream);
            stream_set_blocking($stream, true);
            $deferred->resolve(trim($buffer));
        });

        return $deferred->promise();
    }
}
