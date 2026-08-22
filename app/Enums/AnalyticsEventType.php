<?php

namespace App\Enums;

enum AnalyticsEventType: string
{
    // Visitor Intelligence reuses the existing Event Intelligence stream. These
    // events describe an observed journey without storing browser snapshots.
    case SESSION_START = 'session_start';
    case PAGE_VIEW = 'page_view';
    case PAGE_EXIT = 'page_exit';
    case NAVIGATION = 'navigation';
    case SCROLL_DEPTH = 'scroll_depth';
    case CLICK = 'click';
    case POINTER_MOVE = 'pointer_move';
    case WIDGET_IMPRESSION = 'widget_impression';
    case WIDGET_OPENED = 'widget_opened';
    case WIDGET_CLOSED = 'widget_close';
    case FORM_START = 'form_start';
    case FORM_SUBMIT = 'form_submit';
    case INACTIVITY_START = 'inactivity_start';
    case INACTIVITY_END = 'inactivity_end';
    case SESSION_END = 'session_end';
    case CONVERSATION_STARTED = 'conversation_started';
    case MESSAGE_SENT = 'message_sent';
    case MESSAGE_RECEIVED = 'message_received';
    case CONVERSATION_RESOLVED = 'conversation_resolved';
    case HUMAN_HANDOFF = 'human_handoff';

    case INTENT_DETECTED = 'intent_detected';
    case COMMERCIAL_INTENT_DETECTED = 'commercial_intent_detected';
    case SUPPORT_INTENT_DETECTED = 'support_intent_detected';
    case PURCHASE_INTENT_DETECTED = 'purchase_intent_detected';
    case BOOKING_INTENT_DETECTED = 'booking_intent_detected';
    case PRICING_INTENT_DETECTED = 'pricing_intent_detected';

    case CTA_IMPRESSION = 'cta_impression';
    case CTA_CLICK = 'cta_click';
    case CTA_CONVERSION = 'cta_conversion';
    case PRODUCT_RECOMMENDED = 'product_recommended';
    case PRODUCT_VIEWED = 'product_viewed';
    case PRODUCT_CLICKED = 'product_clicked';
    case PRODUCT_ADDED_TO_CART = 'product_added_to_cart';
    case PURCHASE_COMPLETED = 'purchase_completed';
    case PAGE_RECOMMENDED = 'page_recommended';
    case PAGE_CLICKED = 'page_clicked';
    case DOCUMENT_RECOMMENDED = 'document_recommended';
    case DOCUMENT_CLICKED = 'document_clicked';
    case DOCUMENT_DOWNLOADED = 'document_downloaded';
    case IMAGE_DISPLAYED = 'image_displayed';
    case IMAGE_CLICKED = 'image_clicked';

    case LEAD_CREATED = 'lead_created';
    case LEAD_UPDATED = 'lead_updated';
    case CONTACT_CREATED = 'contact_created';
    case OPPORTUNITY_CREATED = 'opportunity_created';
    case OPPORTUNITY_UPDATED = 'opportunity_updated';
    case OPPORTUNITY_WON = 'opportunity_won';
    case OPPORTUNITY_LOST = 'opportunity_lost';
    case MEETING_PROPOSED = 'meeting_proposed';
    case MEETING_BOOKED = 'meeting_booked';
    case MEETING_CANCELLED = 'meeting_cancelled';
    case APPOINTMENT_CREATED = 'appointment_created';
    case CONVERSION = 'conversion';

    case WORKFLOW_STARTED = 'workflow_started';
    case WORKFLOW_COMPLETED = 'workflow_completed';
    case WORKFLOW_FAILED = 'workflow_failed';
    case AGENT_STARTED = 'agent_started';
    case AGENT_COMPLETED = 'agent_completed';
    case AGENT_FAILED = 'agent_failed';
    case MCP_ACTION_STARTED = 'mcp_action_started';
    case MCP_ACTION_COMPLETED = 'mcp_action_completed';
    case MCP_ACTION_FAILED = 'mcp_action_failed';

    case UNANSWERED_QUESTION = 'unanswered_question';
    case LOW_CONFIDENCE_ANSWER = 'low_confidence_answer';
    case KNOWLEDGE_SOURCE_USED = 'knowledge_source_used';

    case PROACTIVE_TRIGGER_DETECTED = 'proactive_trigger_detected';
    case PROACTIVE_TRIGGER_MATCHED = 'proactive_trigger_matched';
    case PROACTIVE_SEQUENCE_STARTED = 'proactive_sequence_started';
    case PROACTIVE_MESSAGE_SCHEDULED = 'proactive_message_scheduled';
    case PROACTIVE_MESSAGE_SENT = 'proactive_message_sent';
    case PROACTIVE_MESSAGE_FAILED = 'proactive_message_failed';
    case PROACTIVE_MESSAGE_DELIVERED = 'proactive_message_delivered';
    case PROACTIVE_MESSAGE_OPENED = 'proactive_message_opened';
    case PROACTIVE_MESSAGE_CLICKED = 'proactive_message_clicked';
    case PROACTIVE_MESSAGE_REPLIED = 'proactive_message_replied';
    case PROACTIVE_MESSAGE_SKIPPED = 'proactive_message_skipped';
    case PROACTIVE_SEQUENCE_STOPPED = 'proactive_sequence_stopped';
    case PROACTIVE_CONVERSION = 'proactive_conversion';
    case PROACTIVE_LEAD_CREATED = 'proactive_lead_created';
    case PROACTIVE_MEETING_BOOKED = 'proactive_meeting_booked';
    case PROACTIVE_OPPORTUNITY_CREATED = 'proactive_opportunity_created';
    case PROACTIVE_SALE_ATTRIBUTED = 'proactive_sale_attributed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function category(): string
    {
        return match ($this) {
            self::WIDGET_OPENED,
            self::WIDGET_IMPRESSION,
            self::WIDGET_CLOSED,
            self::CONVERSATION_STARTED,
            self::MESSAGE_SENT,
            self::MESSAGE_RECEIVED,
            self::CONVERSATION_RESOLVED,
            self::HUMAN_HANDOFF => 'conversation',

            self::SESSION_START,
            self::PAGE_VIEW,
            self::PAGE_EXIT,
            self::NAVIGATION,
            self::SCROLL_DEPTH,
            self::CLICK,
            self::POINTER_MOVE,
            self::FORM_START,
            self::FORM_SUBMIT,
            self::INACTIVITY_START,
            self::INACTIVITY_END,
            self::SESSION_END => 'journey',

            self::INTENT_DETECTED,
            self::COMMERCIAL_INTENT_DETECTED,
            self::SUPPORT_INTENT_DETECTED,
            self::PURCHASE_INTENT_DETECTED,
            self::BOOKING_INTENT_DETECTED,
            self::PRICING_INTENT_DETECTED => 'intent',

            self::CTA_IMPRESSION,
            self::CTA_CLICK,
            self::CTA_CONVERSION => 'cta',

            self::PRODUCT_RECOMMENDED,
            self::PRODUCT_VIEWED,
            self::PRODUCT_CLICKED,
            self::PRODUCT_ADDED_TO_CART,
            self::PURCHASE_COMPLETED => 'product',

            self::PAGE_RECOMMENDED,
            self::PAGE_CLICKED => 'page',

            self::DOCUMENT_RECOMMENDED,
            self::DOCUMENT_CLICKED,
            self::DOCUMENT_DOWNLOADED => 'document',

            self::IMAGE_DISPLAYED,
            self::IMAGE_CLICKED => 'image',

            self::LEAD_CREATED,
            self::LEAD_UPDATED => 'lead',

            self::CONTACT_CREATED,
            self::OPPORTUNITY_CREATED,
            self::OPPORTUNITY_UPDATED,
            self::OPPORTUNITY_WON,
            self::OPPORTUNITY_LOST => 'crm',

            self::MEETING_PROPOSED,
            self::MEETING_BOOKED,
            self::MEETING_CANCELLED,
            self::APPOINTMENT_CREATED => 'calendar',

            self::CONVERSION => 'crm',

            self::WORKFLOW_STARTED,
            self::WORKFLOW_COMPLETED,
            self::WORKFLOW_FAILED => 'workflow',

            self::AGENT_STARTED,
            self::AGENT_COMPLETED,
            self::AGENT_FAILED => 'agent',

            self::MCP_ACTION_STARTED,
            self::MCP_ACTION_COMPLETED,
            self::MCP_ACTION_FAILED => 'mcp',

            self::UNANSWERED_QUESTION,
            self::LOW_CONFIDENCE_ANSWER,
            self::KNOWLEDGE_SOURCE_USED => 'knowledge',

            self::PROACTIVE_TRIGGER_DETECTED,
            self::PROACTIVE_TRIGGER_MATCHED,
            self::PROACTIVE_SEQUENCE_STARTED,
            self::PROACTIVE_MESSAGE_SCHEDULED,
            self::PROACTIVE_MESSAGE_SENT,
            self::PROACTIVE_MESSAGE_FAILED,
            self::PROACTIVE_MESSAGE_DELIVERED,
            self::PROACTIVE_MESSAGE_OPENED,
            self::PROACTIVE_MESSAGE_CLICKED,
            self::PROACTIVE_MESSAGE_REPLIED,
            self::PROACTIVE_MESSAGE_SKIPPED,
            self::PROACTIVE_SEQUENCE_STOPPED,
            self::PROACTIVE_CONVERSION,
            self::PROACTIVE_LEAD_CREATED,
            self::PROACTIVE_MEETING_BOOKED,
            self::PROACTIVE_OPPORTUNITY_CREATED,
            self::PROACTIVE_SALE_ATTRIBUTED => 'proactive',
        };
    }
}
