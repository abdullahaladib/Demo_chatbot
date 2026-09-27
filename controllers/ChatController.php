<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\ai\ChatService;
use app\models\ChatAuditLog;
use app\models\Employee;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

class ChatController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@']]],
                'denyCallback' => function () {
                    if ($this->action->id === 'ask') {
                        throw new UnauthorizedHttpException('Please sign in.');
                    }
                    return Yii::$app->user->loginRequired();
                },
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['ask' => ['post']],
            ],
        ];
    }

    public function actionIndex(): string
    {
        return $this->render('index', ['me' => Yii::$app->user->identity]);
    }

    /**
     * POST {"question": "..."}  ->  JSON answer.
     *
     * The ONLY thing read from the request is the question text. Who is asking - and
     * therefore the role, :me and :dept - comes from the authenticated session.
     */
    public function actionAsk(): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        /** @var Employee $me */
        $me = Yii::$app->user->identity;
        $question = (string) ($this->request->getBodyParam('question') ?? '');

        return (new ChatService())->ask($me, $question);
    }

    /**
     * Recent audit rows - shows every turn, refusals included, was logged.
     * HR and the CEO see everyone's; other roles see only their own questions.
     */
    public function actionAudit(): string
    {
        /** @var Employee $me */
        $me = Yii::$app->user->identity;
        $query = ChatAuditLog::find()->orderBy(['id' => SORT_DESC]);
        if (!in_array($me->role, ['hr', 'ceo'], true)) {
            $query->andWhere(['employee_id' => $me->id]);
        }
        $provider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
        return $this->render('audit', ['provider' => $provider]);
    }
}
