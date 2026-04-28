<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\Diagnostics\Diagnostic;
use Pi\CodingAgent\Extension\ExtensionLoader;
use Pi\CodingAgent\Extension\ExtensionRunner;
use Pi\CodingAgent\Model\ModelRegistry;

final class DiagnosticsCollector
{
    /**
     * @return list<Diagnostic>
     */
    public function collect(ConsoleContext $context): array
    {
        $diagnostics = [];

        $context->resourceLoader->loadContextFiles($context->cwd);
        $context->resourceLoader->loadSkills($context->cwd);
        $context->resourceLoader->loadPromptTemplates($context->cwd);

        $modelRegistry = new ModelRegistry($context->authStorage, $context->settingsManager);
        $modelRegistry->resolve();

        $extensionLoadResult = (new ExtensionLoader)->discover($context->cwd, $context->settingsManager);
        $extensionRunner = new ExtensionRunner($extensionLoadResult->extensions, $context->cwd);
        $extensionRunner->discoverResources();

        $diagnostics = [
            ...$diagnostics,
            ...$context->authStorage->getDiagnostics(),
            ...$context->settingsManager->getDiagnostics(),
            ...$context->resourceLoader->getDiagnostics(),
            ...$modelRegistry->getDiagnostics(),
            ...$extensionLoadResult->diagnostics,
            ...$extensionRunner->getDiagnostics(),
        ];

        $extensionRunner->dispose();

        return $diagnostics;
    }
}
