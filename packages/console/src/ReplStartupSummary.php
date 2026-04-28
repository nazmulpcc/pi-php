<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\CodingAgentRuntime;

final class ReplStartupSummary
{
    /**
     * @return list<string>
     */
    public function lines(CodingAgentRuntime $runtime): array
    {
        $state = $runtime->getState();
        $messages = count($state->messages);
        $model = $state->model;
        $modelLabel = $model === null
            ? 'unset'
            : sprintf('%s/%s', $model->provider->value, $model->id);
        $sessionLabel = $messages > 0 ? 'existing' : 'new';
        $sessionFile = $state->sessionPath === null ? 'memory-only' : basename($state->sessionPath);
        $toolCount = count($state->toolNames);
        $bashStatus = in_array('bash', $state->toolNames, true) ? 'enabled' : 'disabled';
        $planMode = $this->isPlanModeActive($runtime) ? 'active' : 'off';

        return [
            sprintf('Session: %s (%s)', $state->sessionId, $sessionLabel),
            sprintf('File:    %s', $sessionFile),
            sprintf('Model:   %s', $modelLabel),
            sprintf('Think:   %s', $state->thinkingLevel->value),
            sprintf('Msgs:    %d', $messages),
            sprintf('Tools:   %d loaded, bash %s', $toolCount, $bashStatus),
            sprintf('Plan:    %s', $planMode),
            sprintf('Cwd:     %s', $state->cwd),
        ];
    }

    private function isPlanModeActive(CodingAgentRuntime $runtime): bool
    {
        $entries = $runtime->session->sessionManager->getEntries();

        foreach (array_reverse($entries) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['type'] ?? null) !== 'custom' || ($entry['customType'] ?? null) !== 'plan_mode') {
                continue;
            }

            $data = $entry['data'] ?? null;

            return is_array($data) && (($data['active'] ?? false) === true);
        }

        return false;
    }
}
