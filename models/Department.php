<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * A department of the training ERP (`department`). Employees reference it via
 * personnel_basic_info.dept_id -> DEPT_ID. (The ERP's `setup_department` is a different,
 * unrelated list - do not use it.)
 *
 * @property int $DEPT_ID
 * @property string $DEPT_DESC
 * @property-read string $name
 */
class Department extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'department';
    }

    public function getName(): string
    {
        return trim((string) $this->DEPT_DESC);
    }
}
