<?php

declare(strict_types=1);

namespace Pi\AI;

use Pi\AI\Event\AssistantMessageEvent;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Event\ErrorEvent;
use Pi\AI\Message\AssistantMessage;

/**
 * @extends EventStream<AssistantMessageEvent, AssistantMessage>
 */
final class AssistantMessageEventStream extends EventStream
{
    public function __construct()
    {
        parent::__construct(
            static fn (AssistantMessageEvent $event): bool => $event instanceof DoneEvent || $event instanceof ErrorEvent,
            static function (AssistantMessageEvent $event): AssistantMessage {
                if ($event instanceof DoneEvent) {
                    return $event->message;
                }

                if ($event instanceof ErrorEvent) {
                    return $event->error;
                }

                throw new \RuntimeException('Unexpected event type for final assistant message extraction.');
            },
        );
    }
}
