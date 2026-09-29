<?php

return [
    'enabled' => env('CALLGEAR_ENABLED', false),
    // CallGear Data API (JSON-RPC). Grand Flora is a UAE account: .ae host (the .com one rejects its key).
    'base_url' => env('CALLGEAR_BASE_URL', 'https://dataapi.callgear.ae/v2.0'),
    'access_token' => env('CALLGEAR_ACCESS_TOKEN'),
    'webhook_secret' => env('CALLGEAR_WEBHOOK_SECRET'),
    // Interactive call processing: what we answer CallGear with on each incoming call.
    // Empty = "{}" (no instruction; the scenario continues to its next / fallback step).
    // Example to route by the scenario's return codes: {"returned_code": 1}
    'incoming_reply' => env('CALLGEAR_INCOMING_REPLY', ''),
    'lookback_days' => env('CALLGEAR_LOOKBACK_DAYS', 2),
    // Calls tagged with any of these CallGear tags (comma separated) become complaints.
    'complaint_tags' => env('CALLGEAR_COMPLAINT_TAGS', 'Complaint'),
];
