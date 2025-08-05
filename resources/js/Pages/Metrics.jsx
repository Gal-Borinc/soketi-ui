import React from 'react';
import SoketiMetrics from './SoketiMetrics';

// This component now redirects to SoketiMetrics for direct Soketi scraping approach
export default function Metrics(props) {
    // Pass all props through to the new SoketiMetrics component
    return <SoketiMetrics {...props} />;
}
