<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * What ONE signed-in user may query - mirrors the ERP's own permissions (owner decision):
 *
 *   ERP module tables     every catalogued table used by a module enabled for the user in
 *                         user_module_define (learned by scanning each module's source code).
 *   HR personal data      employee / leave / attendance tables need an HR ADMINISTRATION module
 *                         (portals such as "User Portal" only ever show the user's OWN rows, so
 *                         they do not open these tables). Others use the self-service views.
 *   confidential          salary / payroll / PF / appraisal tables: chatbot tier hr or ceo only.
 *   views                 v_* self-service / team / department / exec views by chatbot tier.
 *
 * Row scope (bound from the session, never from the model):
 *   :group  every table with a group_for column is limited to the company the user signed into
 *   :me     the user's own employee id, for v_my_* / v_team_* views
 *   :dept   the department a dept head heads, for v_dept_* views
 *
 * Column scope: sensitive columns (passwords, bank accounts, NID, DOB...) are not in the
 * catalogue and the AI's MySQL account has no grant on them.
 */
final class AccessPolicy
{
    /** ERP modules that administer HR data (module_file) - not self-service portals. */
    public const HR_ADMIN_MODULE_FILES = ['hrm_mod', 'hrm_mod_new', 'hrm_mod_operation', 'hrm_mod_prototype',
        'hrm_mod_compensation', 'hrm_mod_pf-gratuity', 'hrm_mod_employee_separation'];

    /** Tables holding HR personal data (need an HR admin module). */
    public const HR_TABLE = '/^(hrm_|hris_|elms_|personnel_|essential_info$|education_detail$|employee|emp_|.*attend|.*leave|earn_leaves$|roster)/i';

    /** @var array<string,true> lower-case table/view name => allowed */
    private array $allowed = [];
    /** @var array<string,string> lower-case name => why it is not allowed (for refusal wording) */
    private array $blockedSubject = [];

    public function __construct(public readonly Identity $identity)
    {
        $modules = Catalog::modules();
        $hasHrAdmin = false;
        foreach ($identity->modules as $id) {
            if (in_array($modules[(string) $id]['file'] ?? '', self::HR_ADMIN_MODULE_FILES, true)) {
                $hasHrAdmin = true;
            }
        }
        $privileged = in_array($identity->tier, ['hr', 'ceo'], true);

        foreach (Catalog::tables() as $name => $t) {
            $lower = strtolower($name);
            if ($t['confidential'] && !$privileged) {
                $this->blockedSubject[$lower] = 'salary and payroll information';
                continue;
            }
            if (preg_match(self::HR_TABLE, $lower) && !$hasHrAdmin && !$privileged) {
                $this->blockedSubject[$lower] = "other employees' HR records";
                continue;
            }
            if (array_intersect($identity->modules, $t['modules']) !== []) {
                $this->allowed[$lower] = true;
            } else {
                $this->blockedSubject[$lower] = 'data from ERP modules you do not have access to';
            }
        }

        foreach (Catalog::VIEWS as $view => $v) {
            $tierOk = in_array('*', $v['tiers'], true) || in_array($identity->tier, $v['tiers'], true);
            $scopeOk = match ($v['scope']) {
                'me' => $identity->pbiId !== null,
                'dept' => $identity->departmentId !== null,
                default => true,
            };
            if ($tierOk && $scopeOk) {
                $this->allowed[$view] = true;
            } else {
                $this->blockedSubject[$view] = $v['subject'];
            }
        }
    }

    public function allows(string $name): bool
    {
        return isset($this->allowed[strtolower($name)]);
    }

    /** Plain-English name of what a refused object protects. */
    public function subjectOf(string $name): string
    {
        return $this->blockedSubject[strtolower($name)] ?? 'that data';
    }

    /** @return string[] allowed base tables (catalogue names) */
    public function tables(): array
    {
        return array_values(array_filter(array_keys(Catalog::tables()), fn($t) => $this->allows($t)));
    }

    /** @return string[] allowed views */
    public function views(): array
    {
        return array_values(array_filter(array_keys(Catalog::VIEWS), fn($v) => $this->allows($v)));
    }

    /**
     * How a referenced object must be row-scoped:
     *   ['param' => 'me'|'dept', 'column' => ..., 'required' => true]   scoped view (model must filter)
     *   ['param' => 'group', 'column' => 'group_for', 'required' => false, 'columns' => [...]]   base table
     *   null                                                              no row scope
     */
    public function scopeOf(string $name): ?array
    {
        $lower = strtolower($name);
        if (isset(Catalog::VIEWS[$lower])) {
            $v = Catalog::VIEWS[$lower];
            return $v['scope'] ? ['param' => $v['scope'], 'column' => $v['column'], 'required' => true] : null;
        }
        $t = Catalog::table($lower);
        if ($t && $t['groupFor']) {
            return ['param' => 'group', 'column' => 'group_for', 'required' => false,
                'columns' => array_column($t['columns'], 'name')];
        }
        return null;
    }

    /** Modules (id => name) the user has that have catalogued tables. */
    public function moduleNames(): array
    {
        $out = [];
        foreach (Catalog::modules() as $id => $m) {
            if (in_array((int) $id, $this->identity->modules, true) && $m['tables'] !== []) {
                $out[(int) $id] = $m['name'];
            }
        }
        return $out;
    }
}
