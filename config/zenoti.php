<?php

return [
    'enabled' => env('ZENOTI_ENABLED', false),
    'base_url' => env('ZENOTI_BASE_URL', 'https://api.zenoti.com/v1'),
    'api_key' => env('ZENOTI_API_KEY'),

    // Your Zenoti web address, used to link guests to their Zenoti profile page.
    'web_url' => rtrim(env('ZENOTI_WEB_URL', 'https://grandflorauae.zenoti.com'), '/'),

    // Guests whose full profile (GET guests/{id}) is fetched per sync run.
    // Scheduled runs stop after this many profiles or seconds; Sync now / --all-guests fetch them all.
    'guest_details_per_run' => env('ZENOTI_GUEST_DETAILS_PER_RUN', 500),
    'guest_details_seconds' => env('ZENOTI_GUEST_DETAILS_SECONDS', 240),
    // Sync now without cron runs inside a web request, which the host stops after a few minutes:
    // fetch profiles in short rounds that queue the rest.
    'web_guest_seconds' => env('ZENOTI_WEB_GUEST_SECONDS', 90),
    // Pause between profile calls so a big backlog doesn't use up Zenoti's API quota (1200 ms = 50 a minute).
    'guest_details_delay_ms' => env('ZENOTI_GUEST_DETAILS_DELAY_MS', 3000),
    // Sales report calls are spaced out, and wait for the quota to refill on a 429 (Account quota exceeded).
    'sales_delay_ms' => env('ZENOTI_SALES_DELAY_MS', 1500),
    'quota_wait_seconds' => env('ZENOTI_QUOTA_WAIT_SECONDS', 65),
    'quota_retries' => env('ZENOTI_QUOTA_RETRIES', 4),

    // Shared secret appended to the webhook URL you register in Zenoti:
    // https://your-domain/webhooks/zenoti?token=THIS_VALUE
    'webhook_secret' => env('ZENOTI_WEBHOOK_SECRET'),

    // Organization new centers are attached to when first synced.
    'default_organization' => env('ZENOTI_DEFAULT_ORGANIZATION', 'Main Organization'),

    // Only sync these center ids (comma separated). Empty = every center the key can see.
    'center_ids' => array_filter(explode(',', (string) env('ZENOTI_CENTER_IDS', ''))),

    // Create a login for every synced employee (role below). New logins are
    // disabled until an admin activates them and sets a password.
    'auto_create_users' => env('ZENOTI_AUTO_CREATE_USERS', true),
    'auto_user_role' => env('ZENOTI_AUTO_USER_ROLE', 'employee'),
    'auto_user_active' => env('ZENOTI_AUTO_USER_ACTIVE', false),

    // Item types to request from the sales report, e.g. "0,2,3,4" (empty = all, falling back to one call per type).
    'sales_item_types' => env('ZENOTI_SALES_ITEM_TYPES', ''),

    // Where sales lines come from: "accrual" = POST reports/sales/accrual_basis/flat_file (every line, with
    // sold by / created by; confirmed on production 2026-09-26), "salesreport" = the older GET sales/salesreport,
    // which only returned a handful of lines for this tenant.
    'sales_source' => env('ZENOTI_SALES_SOURCE', 'accrual'),

    'page_size' => 100,
    'max_pages' => 200,

    // Days of appointments / sales re-pulled on every scheduled run (catches edits).
    'lookback_days' => env('ZENOTI_LOOKBACK_DAYS', 3),

    // Endpoint paths, relative to base_url. {center_id} is substituted. Check
    // these against your Zenoti API docs / tenant; `php artisan zenoti:probe`
    // prints raw responses to help.
    'endpoints' => [
        'centers' => env('ZENOTI_EP_CENTERS', 'centers'),
        'employees' => env('ZENOTI_EP_EMPLOYEES', 'centers/{center_id}/employees'),
        // Zenoti has no "list all guests" call (guests/search needs a name/phone/email), so by
        // default guests are created from the guest details on each appointment.
        'guests' => env('ZENOTI_EP_GUESTS', ''),
        'guest' => env('ZENOTI_EP_GUEST', 'guests/{guest_id}'),
        'appointments' => env('ZENOTI_EP_APPOINTMENTS', 'appointments'),
        'sales' => env('ZENOTI_EP_SALES', 'sales/salesreport'),
        'collections' => env('ZENOTI_EP_COLLECTIONS', ''), // payments report; set once confirmed with zenoti:probe
        'leads' => env('ZENOTI_EP_LEADS', ''), // leave empty until the CRM/opportunity endpoint is confirmed
    ],
];
