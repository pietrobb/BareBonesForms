<?php
/** Optional installation configuration. Merge this returned array into config.php to opt in. */
defined('BBF_LOADED') || exit;
return [
    'system_fields' => array_map(static fn($name) => ['name' => $name, 'type' => 'hidden', 'value' => ''], [
        'gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
        'utm_content', 'landing_url', 'referrer', 'touch_at',
    ]),
    'visit_context' => [
        'trigger_params' => ['gclid', 'gbraid', 'wbraid'],
        'params' => ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'],
    ],
    'analytics' => ['umami' => true],
];
