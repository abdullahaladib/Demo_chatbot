<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $created_at
 */
class Department extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%departments}}';
    }
}
