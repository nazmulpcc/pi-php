<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\Session\FilesystemSessionStore;
use Pi\CodingAgent\Session\SessionManager;

final class SessionInspector
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(FilesystemSessionStore $store): array
    {
        $sessions = [];
        foreach ($store->listSessionFiles() as $path) {
            $manager = $store->openManager($path);
            if (! $manager instanceof SessionManager) {
                continue;
            }

            $sessions[] = $this->summarize($manager);
        }

        return $sessions;
    }

    public function resolve(FilesystemSessionStore $store, string $sessionTarget, ?string $cwd = null): SessionManager
    {
        $manager = $store->openManager($sessionTarget, $cwd);
        if (! $manager instanceof SessionManager) {
            throw new \RuntimeException(sprintf('Session not found: %s', $sessionTarget));
        }

        return $manager;
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(SessionManager $manager): array
    {
        $entries = $manager->getEntries();
        $header = $manager->getHeader() ?? [];
        $context = $manager->buildSessionContext();
        $assistantCount = 0;
        $userCount = 0;
        $toolResultCount = 0;

        foreach ($entries as $entry) {
            if (($entry['type'] ?? null) !== 'message') {
                continue;
            }

            $role = $entry['message']['role'] ?? null;
            if ($role === 'assistant') {
                $assistantCount++;
            } elseif ($role === 'user') {
                $userCount++;
            } elseif ($role === 'tool_result') {
                $toolResultCount++;
            }
        }

        return [
            'id' => $manager->getSessionId(),
            'path' => $manager->getSessionFile(),
            'cwd' => $manager->getCwd(),
            'parentSession' => $header['parentSession'] ?? null,
            'createdAt' => $header['timestamp'] ?? null,
            'lastTimestamp' => $manager->getLastTimestamp(),
            'messageCount' => $assistantCount + $userCount + $toolResultCount,
            'userMessages' => $userCount,
            'assistantMessages' => $assistantCount,
            'toolResults' => $toolResultCount,
            'entryCount' => count($entries),
            'thinkingLevel' => $context['thinkingLevel']->value,
            'model' => ($context['model'] ?? null) === null
                ? null
                : $context['model']->provider->value.'/'.$context['model']->id,
        ];
    }
}
