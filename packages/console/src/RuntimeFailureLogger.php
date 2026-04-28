<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\CodingAgentRuntime;
use Pi\CodingAgent\Event\CodingAgentEvent;

final class RuntimeFailureLogger
{
    /**
     * @param  list<CodingAgentEvent>  $recentEvents
     */
    public function log(CodingAgentRuntime $runtime, \Throwable $error, array $recentEvents = []): string
    {
        $state = $runtime->getState();
        $directory = rtrim($state->cwd, '/').'/.pi/logs';
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $sessionLikeName = $state->sessionPath !== null
            ? (preg_replace('/\.jsonl$/', '', basename($state->sessionPath)) ?: $state->sessionId)
            : $state->sessionId;
        $path = $directory.'/'.$sessionLikeName.'.log';

        $entries = $runtime->session->sessionManager->getEntries();
        $tailEntries = array_slice($entries, -20);
        $payload = [
            'timestamp' => gmdate('c'),
            'error' => [
                'class' => $error::class,
                'message' => $error->getMessage(),
                'trace' => $error->getTraceAsString(),
            ],
            'state' => $state,
            'recentEvents' => array_map(
                static fn (CodingAgentEvent $event): array => $event->jsonSerialize(),
                $recentEvents,
            ),
            'recentSessionEntries' => array_values($tailEntries),
        ];

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        return $path;
    }
}
