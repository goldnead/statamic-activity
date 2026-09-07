<?php

return [

    // Labels for the settings screen. The screen itself belongs to
    // statamic-brand-context; this addon supplies only the field list
    // (Support\Settings) and the words for it.

    'permission_manage_settings' => 'Manage activity settings',

    'groups' => [

        'recording' => [
            'title' => 'Recording',
            'description' => 'Whether anything is written at all. The source label and the queue stay in config/activity.php: the first is on every row already written and cuts the past in two when it changes, the second belongs to the operation of the machine.',
        ],

        'context' => [
            'title' => 'Request context',
            'description' => 'What of the request ends up in the row. Each field is switchable on its own because each carries a different weight. IP addresses are never captured; the browser identification only as a coarse category, never verbatim.',
        ],

        'sanitizer' => [
            'title' => 'Filter before writing',
            'description' => 'Runs on every write. This is the enforcement point for rules like "this domain never mirrors into a central store" and "this field is mirrored nowhere". What is filtered here never reaches the database, and no filter brings back what is already in it.',
        ],

        'retention' => [
            'title' => 'Retention',
            'description' => 'Both take effect when activity:prune and activity:anonymize run, and both are irreversible then. Check a change with --dry-run first: the command tells you how many rows would be affected without touching one. The values are held per brand, while both commands work across the whole store and read the value of the brand they are started under. Per-event-type windows stay in config/activity.php.',
        ],

        'cp' => [
            'title' => 'Control Panel',
            'description' => 'The read-only inspector. Whether it exists at all stays in config/activity.php: that is read while the routes are being registered, so a change made here would only arrive after the next deploy.',
        ],

    ],

    'fields' => [

        'enabled' => [
            'label' => 'Record activity',
            'description' => 'Off, nothing is written and no producer fires. The case this is for is a copy of production data on a staging box that should not start producing events of its own. Existing rows stay; only new ones stop appearing.',
        ],

        'context_capture' => [
            'label' => 'Capture request context at all',
            'description' => 'The master switch over the four fields below. Off, nothing from the request is recorded whatever the individual switches say. Applies from the next event, not retroactively.',
        ],
        'context_utm' => [
            'label' => 'Campaign parameters (utm_*)',
            'description' => 'Where the visit came from, as it stood in the address bar. Off, you lose the link between events and campaigns from that point on; rows already written keep it.',
        ],
        'context_referrer' => [
            'label' => 'Referring page',
            'description' => 'The address the visitor came from. It can carry somebody else\'s page and its parameters with it, which is why it is switchable on its own.',
        ],
        'context_page_url' => [
            'label' => 'Page visited',
            'description' => 'Your own address, where the event happened. On pages whose address already says something about the person, this is more than a waypoint.',
        ],
        'context_user_agent_category' => [
            'label' => 'Kind of device',
            'description' => 'A coarse category, never the full user agent string. Off, the row says nothing about the device.',
        ],

        'sanitizer_strip_keys' => [
            'label' => 'Keys to strip',
            'description' => 'Names removed from properties and context before writing, matched as substrings at any depth: "token" also catches "access_token". A name belongs here as soon as a new field carrying a secret turns up anywhere. Removing a name applies to new rows at once and brings nothing back from old ones.',
        ],
        'sanitizer_blocked_event_types' => [
            'label' => 'Blocked event types',
            'description' => 'Event types that are never written. This is where a rule like "this domain never mirrors into a central store" is actually enforced rather than merely documented.',
        ],
        'sanitizer_max_payload_bytes' => [
            'label' => 'Largest payload (bytes)',
            'description' => 'Anything above this is truncated, not dropped. Lowering it truncates from the next event on; rows already written stay as long as they are.',
        ],

        'retention_days' => [
            'label' => 'Retention (days)',
            'description' => 'Rows older than this are deleted for good by the next run of activity:prune. Lowering this number is therefore not a setting but a deletion order taking effect at the next run: from 365 to 90 means nine months of the store disappear when it next runs. Empty keeps everything. Check a reduction with activity:prune --dry-run first.',
        ],
        'retention_anonymize_after_days' => [
            'label' => 'Anonymise after (days)',
            'description' => 'Usually what you want instead of deletion: the next run of activity:anonymize strips the personal fields from older rows and leaves the countable fact standing. This is irreversible too. Empty means only on explicit instruction, such as an erasure request.',
        ],

        'cp_per_page' => [
            'label' => 'Rows per page',
            'description' => 'How many events the inspector shows at once as long as nobody chooses otherwise.',
        ],

    ],

];
