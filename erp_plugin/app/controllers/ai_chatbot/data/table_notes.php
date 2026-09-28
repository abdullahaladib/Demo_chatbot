<?php
/**
 * Curated notes on the ERP's key tables - what a column means in ERP terms (learned from the
 * training database and the ERP source). Shown to the model by describeTables() and, for the
 * starred tables, in the prompt's table index. Everything else is described from the schema.
 */
return [
    // ------------------------------------------------------------ Financial Accounting
    'journal' => 'POSTED ACCOUNTING VOUCHERS, one row per voucher line (double entry). A voucher = all rows with the same jv_no (voucher number) and jv_date. dr_amt / cr_amt = debit / credit amount of the line; ledger_id -> accounts_ledger (the account); sub_ledger -> general_sub_ledger.sub_ledger_id (party: customer/vendor/employee sub-ledger); tr_from = voucher type (Purchase, Sales, Receipt, Payment, Journal, Contra, COGS, Vendor_payment, vendor_invoice_receive, Opening, AssetPurchase...); tr_no = source document number; narration = description; cc_code = cost centre. Ledger balance = SUM(dr_amt) - SUM(cr_amt) (plus accounts_ledger.opening_balance). Ignore jv_date = 0000-00-00.',
    'secondary_journal' => 'Voucher lines awaiting / used for secondary approval - same columns and meaning as journal (jv_no, jv_date, ledger_id, dr_amt, cr_amt, tr_from). Use journal for posted figures unless the user asks about secondary/unapproved vouchers.',
    'accounts_ledger' => 'CHART OF ACCOUNTS: one row per ledger (account). ledger_name, ledger_group_id -> ledger_group.group_id, opening_balance + balance_type, acc_class / acc_sub_class. Journal lines point here via ledger_id.',
    'ledger_group' => 'Groups of ledgers (e.g. Cash in Hand, Bank Accounts, Sundry Debtors, Sundry Creditors, Sales, Expenses). group_name; group_class / acc_class = asset, liability, income, expense...',
    'sub_ledger' => 'Sub-ledgers under an accounts_ledger (ledger_id). sub_ledger = name. Hierarchy: ledger_group > accounts_ledger > sub_ledger > sub_sub_ledger.',
    'sub_sub_ledger' => 'Sub-sub-ledgers under a sub_ledger (sub_ledger_id -> sub_ledger.sub_ledger_id). sub_sub_ledger = name, opening_balance.',
    'general_sub_ledger' => 'PARTY / sub-ledger accounts (customers, vendors, employees, banks) used on voucher lines: journal.sub_ledger = general_sub_ledger.sub_ledger_id. sub_ledger_name = party name; ledger_id = its control ledger; tr_from/type = what kind of party.',

    // ------------------------------------------------------------ Sales & Distribution
    'sale_do_master' => 'SALES ORDERS / delivery orders (DO), one row per order. do_no, do_date, dealer_code -> dealer_info (the customer), depot_id -> warehouse, status (MANUAL, UNCHECKED, CHECKED, PROCESSING, COMPLETED...), salesman, discount, vat_amt, received_amt.',
    'sale_do_details' => 'Sales order LINES: do_no -> sale_do_master, item_id -> item_info, total_unit (quantity), unit_price, total_amt, discount_amt, vat_amt, total_amt_with_vat.',
    'dealer_info' => 'CUSTOMERS / dealers / distributors: dealer_code, dealer_name_e (name), dealer_type, area/zone/region codes, contact_no, credit_limit, status, account_code (ledger).',

    // ------------------------------------------------------------ Procurement
    'purchase_master' => 'PURCHASE ORDERS, one row per PO: po_no, po_date, vendor_id -> vendor, warehouse_id -> warehouse, status, purchase_type, vat, tax.',
    'purchase_invoice' => 'Purchase order LINES: po_no -> purchase_master, item_id -> item_info, qty, rate, amount, vat_amt, grand_amount, vendor_id.',
    'vendor' => 'SUPPLIERS / vendors: vendor_id, vendor_name, vendor_category, contact person, address, status, ledger_id / sub_ledger_id (their accounts).',

    // ------------------------------------------------------------ Inventory
    'journal_item' => 'STOCK MOVEMENTS (inventory journal), one row per item movement: ji_date, item_id -> item_info, warehouse_id -> warehouse, item_in / item_ex = quantity in / out, item_price, final_stock / final_price = running stock after the movement, tr_from = movement type (Purchase, Issue, Sales, Batch issue, Consumption, Production Receive...), tr_no = source document. Current stock of an item in a warehouse = SUM(item_in) - SUM(item_ex).',
    'item_info' => 'ITEMS / products: item_id, item_name, sub_group_id -> item_sub_group, unit_name, cost_price, sale_price, min_stock, status, item_type / product_type.',
    'warehouse' => 'WAREHOUSES / depots / stores: warehouse_id, warehouse_name, warehouse_type, address, status.',
    'item_group' => 'Item groups (top level of the item hierarchy).',
    'item_sub_group' => 'Item sub-groups: sub_group_id, sub_group_name, group_id -> item_group.',

    // ------------------------------------------------------------ organisation
    'user_group' => 'Companies / business units in this ERP (id, group_name). Most business tables carry group_for = the company they belong to; queries are automatically limited to the signed-in company.',
    'personnel_basic_info' => 'EMPLOYEES: pbi_id, pbi_code, pbi_name (name), dept_id -> department.DEPT_ID, desg_id -> designation.DESG_ID, pbi_doj (joining date), pbi_job_status (In Service / Not In Service), incharge_id (line manager pbi_id). HR administrators only.',
    'department' => 'Departments: DEPT_ID, DEPT_DESC (name).',
    'designation' => 'Job titles: DESG_ID, DESG_DESC (title).',
    'hrm_leave_info' => 'LEAVE REQUESTS of all employees: PBI_ID, type (leave type id -> hrm_leave_type.id, or text), s_date, e_date, total_days, leave_status (GRANTED / Pending / Cancel), incharge_status. HR administrators only - others use v_my_leave_requests.',
    'hrm_att_summary' => 'DAILY ATTENDANCE of all employees: emp_id (= pbi_id), att_date, in_time, out_time, present, absent, late_min, leave_id. HR administrators only - others use v_my_attendance_daily.',
];
