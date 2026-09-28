<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\base\Model;

/**
 * Username + password sign-in against the training ERP's logins (`user_activity_management`).
 * Only logins linked to an IN-SERVICE employee may sign in: the chatbot's scopes (:me, :dept,
 * team) are all anchored on the employee record.
 */
class LoginForm extends Model
{
    public string $username = '';
    public string $password = '';

    private ?Employee $_employee = null;

    public function rules(): array
    {
        return [
            [['username', 'password'], 'required'],
            ['username', 'string', 'max' => 255],
            ['password', 'validatePassword'],
        ];
    }

    public function attributeLabels(): array
    {
        return ['username' => 'Username'];
    }

    public function validatePassword(string $attribute): void
    {
        if ($this->hasErrors()) {
            return;
        }
        $user = ErpUser::findByUsername($this->username);
        $employee = $user !== null && $user->isActive() && (int) $user->PBI_ID > 0
            ? Employee::findIdentity((int) $user->PBI_ID)
            : null;
        if ($employee === null || !$user->validatePassword($this->password)) {
            $this->addError($attribute, 'Incorrect username or password.');
            return;
        }
        $this->_employee = $employee;
    }

    public function login(): bool
    {
        return $this->validate() && Yii::$app->user->login($this->_employee);
    }
}
