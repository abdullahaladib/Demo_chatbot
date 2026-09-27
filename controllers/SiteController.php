<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use app\models\Employee;
use app\models\LoginForm;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ErrorAction;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SiteController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['index', 'logout'],
                'rules' => [
                    ['actions' => ['index', 'logout'], 'allow' => true, 'roles' => ['@']],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                    'switch-user' => ['post'],
                ],
            ],
        ];
    }

    public function actions(): array
    {
        return [
            'error' => ['class' => ErrorAction::class],
        ];
    }

    public function actionIndex(): string
    {
        /** @var Employee $me */
        $me = Yii::$app->user->identity;
        return $this->render('index', ['me' => $me]);
    }

    public function actionLogin(): Response|string
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new LoginForm();
        if ($model->load($this->request->post()) && $model->login()) {
            return $this->goBack();
        }

        $model->password = '';
        return $this->render('login', ['model' => $model]);
    }

    public function actionLogout(): Response
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }

    /**
     * Demo role switcher: become any seeded employee without a password.
     * Gated by params['demoRoleSwitcher']; POST + CSRF only.
     */
    public function actionSwitchUser(): Response
    {
        if (empty(Yii::$app->params['demoRoleSwitcher'])) {
            throw new ForbiddenHttpException('The demo role switcher is disabled.');
        }

        $employee = Employee::findIdentity((int) $this->request->post('id'));
        if ($employee === null) {
            throw new NotFoundHttpException('Unknown employee.');
        }

        // login() regenerates the session id, so switching is a real new session.
        Yii::$app->user->logout();
        Yii::$app->user->login($employee);
        Yii::$app->session->setFlash('info',
            "Now signed in as {$employee->full_name} ({$employee->getRoleLabel()}).");

        return $this->goHome();
    }
}
