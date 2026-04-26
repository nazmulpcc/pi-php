<?php

declare(strict_types=1);

namespace Pi\Agent\Event;

enum EventType: string
{
    case AgentStart = 'agent_start';
    case AgentEnd = 'agent_end';
    case TurnStart = 'turn_start';
    case TurnEnd = 'turn_end';
    case MessageStart = 'message_start';
    case MessageUpdate = 'message_update';
    case MessageEnd = 'message_end';
    case ToolExecutionStart = 'tool_execution_start';
    case ToolExecutionUpdate = 'tool_execution_update';
    case ToolExecutionEnd = 'tool_execution_end';
}
