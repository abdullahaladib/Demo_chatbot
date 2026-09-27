<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\base\Model;

/**
 * Email + password login against the employees table.
 */
class LoginForm extends Model
{
    public string $email = '';
    public string $password = '';
    public bool $rememberMe = false;

    private ?Employee $_employee = null;
    private bool $_loaded = false;

    public function rules(): array
    {
        return [
            [['email', 'password'], 'required'],
            ['email', 'email'],
            ['rememberMe', 'boolean'],
            ['password', 'validatePassword'],
        ];
    }

    public function validatePassword(string $attribute): void
    {
        if (!$this->hasErrors()) {
            $employee = $this->getEmployee();
            if ($employee === null || !$employee->validatePassword($this->password)) {
                $this->addError($attribute, 'Incorrect email or password.');
            }
        }
    }

    public function login(): bool
    {
        return $this->validate()
            && Yii::$app->user->login($this->getEmployee(), $this->rememberMe ? 3600 * 24 * 30 : 0);
    }

    public function getEmployee(): ?Employee
    {
        if (!$this->_loaded) {
            $this->_employee = Employee::findByEmail($this->email);
            $this->_loaded = true;
        }
        return $this->_employee;
    }
}
