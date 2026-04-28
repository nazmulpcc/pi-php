<?php

declare(strict_types=1);

use Pi\Agent\ThinkingLevel;

use function Pi\AI\getModel;

return function ($api): void {
    $state = [
        'active' => false,
        'provider' => null,
        'modelId' => null,
    ];

    $planTools = ['read', 'find', 'grep', 'ls'];

    $hydrateState = static function (mixed $sessionManager) use (&$state): void {
        if (! is_object($sessionManager) || ! method_exists($sessionManager, 'getEntries')) {
            return;
        }

        $state = [
            'active' => false,
            'provider' => null,
            'modelId' => null,
        ];

        foreach ($sessionManager->getEntries() as $entry) {
            if (($entry['type'] ?? null) !== 'custom' || ($entry['customType'] ?? null) !== 'plan_mode') {
                continue;
            }

            $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
            $state = [
                'active' => (bool) ($data['active'] ?? false),
                'provider' => isset($data['provider']) && is_string($data['provider']) && $data['provider'] !== '' ? $data['provider'] : null,
                'modelId' => isset($data['modelId']) && is_string($data['modelId']) && $data['modelId'] !== '' ? $data['modelId'] : null,
            ];
        }
    };

    $planFilePath = static function (mixed $sessionManager, string $cwd): string {
        $baseName = null;
        if (is_object($sessionManager) && method_exists($sessionManager, 'getSessionFile')) {
            $sessionFile = $sessionManager->getSessionFile();
            if (is_string($sessionFile) && $sessionFile !== '') {
                $baseName = preg_replace('/\.jsonl$/', '', basename($sessionFile));
            }
        }
        if (($baseName === null || $baseName === '') && is_object($sessionManager) && method_exists($sessionManager, 'getSessionId')) {
            $baseName = $sessionManager->getSessionId();
        }

        $baseName = is_string($baseName) && $baseName !== '' ? $baseName : 'plan';

        return rtrim($cwd, '/').'/.pi/plans/'.$baseName.'.md';
    };

    $extractText = static function (array $message): string {
        $parts = [];
        foreach ($message['content'] ?? [] as $content) {
            if (is_array($content) && ($content['type'] ?? null) === 'text') {
                $parts[] = (string) ($content['text'] ?? '');
            }
        }

        return trim(implode("\n", array_filter($parts, static fn (string $part): bool => $part !== '')));
    };

    $saveSession = static function (mixed $sessionManager): void {
        if (is_object($sessionManager) && method_exists($sessionManager, 'save')) {
            $sessionManager->save();
        }
    };

    $api->registerCommand('plan', 'Enable read-only planning mode for the current session', function (string $args, $context) use ($api, &$state, $planTools, $planFilePath, $saveSession): string {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($args)) ?: [], static fn (string $part): bool => $part !== ''));
        $provider = null;
        $modelId = null;

        if (count($parts) === 1 && str_contains($parts[0], '/')) {
            [$provider, $modelId] = array_pad(explode('/', $parts[0], 2), 2, null);
        } elseif (count($parts) === 2) {
            [$provider, $modelId] = $parts;
        } elseif ($parts !== []) {
            return "Usage: /plan\nUsage: /plan provider model\n";
        }

        if (is_string($provider) && $provider !== '' && is_string($modelId) && $modelId !== '') {
            $model = getModel($provider, $modelId);
            if ($model === null) {
                return sprintf("Model not found: %s/%s\n", $provider, $modelId);
            }

            $api->setModel($model);
            $state['provider'] = $provider;
            $state['modelId'] = $modelId;
        }

        $state['active'] = true;
        $api->setThinkingLevel(ThinkingLevel::High);
        $api->setActiveTools($planTools);
        $api->appendEntry('plan_mode', [
            'active' => true,
            'provider' => $state['provider'],
            'modelId' => $state['modelId'],
        ]);
        $saveSession($context->sessionManager);

        return sprintf(
            "Plan mode enabled. Read-only tools are now active: %s.\nThe assistant will explore and clarify first, then write a markdown plan to %s when it produces a response starting with \"# Plan\".\n",
            implode(', ', $planTools),
            $planFilePath($context->sessionManager, $context->cwd),
        );
    });

    $api->on('session_start', function (array $event, $context) use (&$state, $hydrateState, $api, $planTools): void {
        $hydrateState($context->sessionManager);
        if (! $state['active']) {
            return;
        }

        $api->setActiveTools($planTools);
    });

    $api->on('input', function (array $event) use (&$state): ?array {
        if (! $state['active']) {
            return null;
        }

        $input = trim((string) ($event['input'] ?? ''));
        if ($input === '') {
            return null;
        }

        return [
            'input' => <<<TEXT
Plan mode is active.
Work in read-only mode. Explore the codebase first and ask clarifying questions where needed before finalizing anything.
Do not edit files, do not claim implementation work, and keep your attention on discovery, risks, assumptions, and sequence.
When you are ready to finalize the plan, respond in markdown beginning with "# Plan".

User request:
$input
TEXT,
        ];
    });

    $api->on('message_end', function (array $event, $context) use (&$state, $extractText, $planFilePath, $api, $saveSession): void {
        if (! $state['active']) {
            return;
        }

        $message = is_array($event['message'] ?? null) ? $event['message'] : null;
        if ($message === null || ($message['role'] ?? null) !== 'assistant') {
            return;
        }

        if (in_array((string) ($message['stopReason'] ?? ''), ['error', 'aborted'], true)) {
            return;
        }

        $markdown = $extractText($message);
        if (! preg_match('/^\s*#\s+Plan\b/m', $markdown)) {
            return;
        }

        $path = $planFilePath($context->sessionManager, $context->cwd);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $overwrote = is_file($path);
        file_put_contents($path, rtrim($markdown)."\n");
        $api->appendEntry('plan_file', [
            'path' => $path,
            'overwrote' => $overwrote,
        ]);
        $saveSession($context->sessionManager);

        $context->ui->notify(
            $overwrote
                ? sprintf('Overwrote existing plan file for this session: %s', $path)
                : sprintf('Wrote plan file for this session: %s', $path),
            $overwrote ? 'warning' : 'info',
        );
    });
};
