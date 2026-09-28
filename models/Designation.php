<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * A job title of the training ERP (`designation`), referenced by personnel_basic_info.desg_id.
 *
 * @property int $DESG_ID
 * @property string $DESG_DESC
 */
class Designation extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'designation';
    }
}
