<?php

return [
    /*
    |--------------------------------------------------------------------------
    | High-Risk Approval Actions
    |--------------------------------------------------------------------------
    |
    | Each action can enforce maker-checker approvals.
    | - required_approvals: minimum checker approvals needed.
    | - audience_threshold/row_threshold: only for volume-sensitive actions.
    |
    */
    'actions' => [
        'campaign.mass_send' => [
            'enabled' => true,
            'required_approvals' => 2,
            'audience_threshold' => 100,
        ],
        'leads.export' => [
            'enabled' => true,
            'required_approvals' => 2,
            'row_threshold' => 1,
        ],
        'lead.delete' => [
            'enabled' => true,
            'required_approvals' => 1,
        ],
        'lead.merge' => [
            'enabled' => true,
            'required_approvals' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pending Approval Expiry
    |--------------------------------------------------------------------------
    */
    'pending_expiry_hours' => 72,
];
