<?php

return [
    // Demo convenience: a navbar dropdown that logs you in as any of the demo people below
    // without a password. It exists so the demo can switch roles in two clicks.
    // Turn OFF for anything that is not a demo.
    'demoRoleSwitcher' => true,

    // Who appears in the "Switch user" menu and on the sign-in page (ERP usernames, in
    // this order): one person per chatbot tier. Every in-service employee can still sign
    // in with their username and the demo password.
    'demoPeople' => [
        'bimol',    // Bimol Chandra Das - CTO                      -> Executive (ai_role_assignment)
        'hr.demo',  // Farzana Rahman (Demo HR) - HR Manager        -> HR (created for the demo)
        '1005',     // Payer Alam Rony - CTO (Operation)            -> Department Head of "Engineer"
        '1002',     // Kawsar Mahmud - Sr. Project Manager          -> Manager (3 direct reports)
        'tanvir',   // Tanvir Ahmmed - Jr. Software Engineer        -> Employee
        '1954',     // Md Nizam Uddin (Tanim) - Software Engineer   -> Employee (reports to a PM)
    ],
];
