<?php

namespace vmybook\sentry;

use Yii;
use yii\base\BootstrapInterface;
use yii\base\Application;
use yii\base\Event;
use yii\base\Module;
use Sentry\Tracing\SpanStatus;
use yii\helpers\ArrayHelper;
use notamedia\sentry\SentryTarget;

class SentryPerformanceModule extends Module implements BootstrapInterface 
{
    public ?string $dsn;
    public bool $enabled = true;
    public array $targetOptions = [];

    public function bootstrap($app)
    {
        if (!$this->enabled) {
            return;
        }

        $app->on(Application::EVENT_BEFORE_REQUEST, [$this, 'startTransaction']);
        $app->on(Application::EVENT_AFTER_REQUEST, [$this, 'finishTransaction']);

        \Yii::$container->set('yii\log\Logger', [
            'class' => SentryPerformanceLogger::class
        ]);
        
        Yii::setLogger(Yii::createObject(SentryPerformanceLogger::class));

        $defaultConfig = [
            'enabled' => $this->enabled,
            'dsn' => $this->dsn,
            'levels' => ['error', 'warning'],
            'context' => true,
            'clientOptions' => [
                'release' => 'litportal@1.0',
                'traces_sample_rate' => YII_DEBUG ? 1 : 0.1,
                'sample_rate' => YII_DEBUG ? 1 : 0.1,
                //'attach_stacktrace' => true,
                //'send_default_pii' => true,
            ],
            'except' => [
                'yii\web\HttpException:404',
                'yii\web\NotFoundHttpException',
                'yii\web\UnauthorizedHttpException',
                'yii\debug*',
            ],
        ];

        $app->getLog()->targets['sentry'] = new SentryTarget(
            ArrayHelper::merge($defaultConfig, $this->targetOptions)
        );
    }

    public function startTransaction(Event $event)
    {
        $transactionContext = new \Sentry\Tracing\TransactionContext();
        $transactionContext->setName(Yii::$app->request->method.' /'.Yii::$app->request->pathInfo);
        $transactionContext->setOp('http.request');
        // Start the transaction
        $transaction = \Sentry\startTransaction($transactionContext);
        // Set the current transaction as the current span so we can retrieve it later
        \Sentry\SentrySdk::getCurrentHub()->setSpan($transaction);

    }

    public function finishTransaction(Event $event)
    {
        $transaction = \Sentry\SentrySdk::getCurrentHub()->getTransaction();
        if ($transaction !== null) {
            $transaction->setStatus(SpanStatus::createFromHttpStatusCode(\Yii::$app->response->statusCode));
            $transaction->finish();
        }
    }
}