import React from 'react';
import WebhookMetrics from './WebhookMetrics';

// This component now redirects to WebhookMetrics for the new webhook-based approach
export default function Metrics(props) {
    // Pass all props through to the new WebhookMetrics component
    return <WebhookMetrics {...props} />;
}
