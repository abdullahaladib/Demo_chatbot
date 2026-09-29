<?php
/**
 * Starting content for a company's chatbot tables, written by the automatic install only when
 * the table is EMPTY (never overwrites what HR has entered). Exported from the training company
 * (cid 'training') on 2026-09-29; rows marked "drafted for the demo" and the demo HR person left out.
 * Roles are applied only if that employee exists and is In Service in the company's database.
 */
return array (
  'training' => 
  array (
    'knowledge_base' => 
    array (
      0 => 
      array (
        'section' => 'leave',
        'title' => 'Leave entitlement per year',
        'body' => 'Paid leave per calendar year: Casual Leave (CL) - 15 days; Sick Leave(SL) - 6 days; Marriage Leave (ML) - 10 days; Maternity Leave - 30 days; Paid - 5 days. Other leave types, granted case by case with no fixed quota: Adjust Leave With Balance, Leave Without Pay (LWP), Extra Ordinary Leave, Adjustment Leave Withot Balance, Administrative Leave, Special Leave. A few employees are on a special leave rule with different quotas. Unused leave does not carry over unless HR approves it. Ask me "how many leave days do I have left" to see your own balance.',
      ),
      1 => 
      array (
        'section' => 'hours',
        'title' => 'Office hours',
        'body' => 'The standard Day Shift runs from 10:20 to 18:00, with a break around 13:00. Check-in is recorded by the office attendance device. Arriving more than 20 minutes after the start time is recorded as late.',
      ),
      2 => 
      array (
        'section' => 'hours',
        'title' => 'Weekly holiday',
        'body' => 'Friday is the weekly holiday for everyone. Saturday to Thursday are working days (a few employees also have Saturday off by their roster).',
      ),
      3 => 
      array (
        'section' => 'hours',
        'title' => 'Late attendance',
        'body' => 'Late arrivals are handled under these policies, depending on the employee\'s rule: Mark late, no deduction; Deduct from casual leave; Deduct pro-rata; Warning letter after limit. Attendance, late days and overtime are summarised monthly.',
      ),
      4 => 
      array (
        'section' => 'holidays',
        'title' => 'Public holidays 2026',
        'body' => 'Shab-e-Barat 3 Feb - Moon dependent; International Mother Language Day 21 Feb; Eid-ul-Fitr 20 Mar (3 days) - Three-day factory closure; Independence Day 26 Mar - Comp-off granted if it lands on the weekly off; Bengali New Year (Pohela Boishakh) 14 Apr; May Day 1 May; Eid-ul-Adha 27 May (3 days); Ashura 25 Jun; Factory Annual Maintenance 16 Jul (2 days) - Production floors only; National Mourning Day 15 Aug; Janmashtami 26 Aug - Optional for Hindu employees; Durga Puja (Bijoya Dashami) 20 Oct (2 days); Founders Day 12 Nov - Half day, staff lunch; Victory Day 16 Dec; Christmas Day 25 Dec; Year-end Closing 31 Dec - Accounts close early.',
      ),
      5 => 
      array (
        'section' => 'organisation',
        'title' => 'Departments',
        'body' => 'Departments with active staff (exact names as stored): Engineer (31); Business Analysis (1); Marketing (1); Admin (1); HR & Admin (1). The software engineering team is the department named "Engineer".',
      ),
      6 => 
      array (
        'section' => 'organisation',
        'title' => 'Job titles in use',
        'body' => 'Chief Technical Officer(CTO); Sr. Project Manager; CTO.(Operation); Sr.Business Analyst; Sr. Software Engineer; Project Manager; Business Analyst; Software Engineer; Sr.System Analyst; Jr. Software Engineer; Audit Manager; HR Manager.',
      ),
      7 => 
      array (
        'section' => 'assistant',
        'title' => 'About this assistant',
        'body' => 'This assistant answers policy questions from this knowledge base, and ERP data questions only within the modules and records your ERP login is allowed to see. It cannot change any data.',
      ),
    ),
    'roles' => 
    array (
      0 => 
      array (
        'pbi_id' => 1001,
        'role' => 'ceo',
        'dept_id' => NULL,
        'note' => 'Top active executive (CTO); the ERP CEO/MD are Not In Service',
      ),
      1 => 
      array (
        'pbi_id' => 1005,
        'role' => 'dept_head',
        'dept_id' => 10,
        'note' => 'CTO (Operation) as head of department 10 Engineer',
      ),
    ),
  ),
);
