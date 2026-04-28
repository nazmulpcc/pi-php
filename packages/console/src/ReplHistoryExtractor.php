<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\Agent\AgentMessage;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Message\UserMessage;

final class ReplHistoryExtractor
{
    /**
     * @param  list<AgentMessage>  $messages
     * @return list<string>
     */
    public function userMessages(array $messages, int $limit = 50): array
    {
        $history = [];
        foreach ($messages as $message) {
            if (! $message instanceof UserMessage) {
                continue;
            }

            $text = $this->textFromMessage($message);
            if ($text !== '') {
                $history[] = $text;
            }
        }

        return array_slice($history, max(0, count($history) - $limit));
    }

    private function textFromMessage(UserMessage $message): string
    {
        $parts = [];
        foreach ($message->content as $content) {
            if ($content instanceof TextContent) {
                $parts[] = $content->text;
            }
        }

        return trim(implode("\n\n", $parts));
    }
}
