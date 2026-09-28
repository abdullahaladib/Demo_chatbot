<?php
/**
 * Suggested questions shown as chips, per ERP module (module_file). The widget shows up to
 * five, drawn from the modules the signed-in user actually has. They are only examples:
 * nothing is hard-coded behind them - the AI writes the SQL for these like any other question.
 * Written against the training data (real ledgers, customers, items and dates exist for them).
 */
return [
    'acc' => [
        'How many vouchers were posted this month?',
        'Show the 5 largest payment vouchers this year',
        'What is the balance of the cash ledger?',
    ],
    'sales' => [
        'Who are our top 5 customers by sales this year?',
        'How many sales orders are still unchecked?',
    ],
    'purchase' => [
        'Which vendors did we buy the most from this year?',
        'How many purchase orders were raised last month?',
    ],
    'warehouse' => [
        'Which items are below their minimum stock?',
        'What is the current stock of each item in the main warehouse?',
    ],
    'production' => ['How many production receives were posted this year?'],
    'crm_mod' => ['How many CRM leads do we have by status?'],
    'asset_mod' => ['How many fixed assets were registered this year?'],
    'lc_import' => ['How many import L/Cs were opened this year?'],
    'hrm_mod' => ['How many employees are in each department?', 'Who has pending leave requests?'],
    // self-service (any user linked to an employee record)
    '_self' => ["What's our leave policy?", 'How many casual leave days do I have left?'],
];
