<?php

namespace vmybook\sentry;

use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use yii\log\Logger;

class SentryPerformanceLogger extends Logger
{
    protected static ?Transaction $transaction = null;
    
    /** @var Span[]  */
    protected static array $spans = [];

    public function log($message, $level, $category = 'application')
    {
        if (!self::$transaction) {
            self::$transaction = \Sentry\SentrySdk::getCurrentHub()->getTransaction();
        }
        if ($level === self::LEVEL_PROFILE_BEGIN) {
            $this->startSpan($message,$category);
        }
        if ($level === self::LEVEL_PROFILE_END) {
            $this->stopSpan($message,$category);
        }
        parent::log($message, $level, $category); // TODO: Change the autogenerated stub
    }

    public function flush($final = false)
    {
        parent::flush($final);
        if ($final) {
            $transaction = \Sentry\SentrySdk::getCurrentHub()->getTransaction();
            if ($transaction !== null) {
                $transaction->setStatus(SpanStatus::createFromHttpStatusCode(\Yii::$app->response->statusCode));
                $transaction->finish();
            }
        }
    }

    protected function startSpan($message, $category)
    {
        $context = new \Sentry\Tracing\SpanContext();
        $context->setOp(self::getSentryOpFromCategory($category));
        $context->setDescription($message);
        $parent = array_pop(self::$spans);
        if (!$parent) {
            $parent = self::$transaction;
        }
        if ($parent) {
            if ($parent instanceof Span) {
                $context->setParentSpanId($parent->getSpanId());
            }
            $span = $parent->startChild($context);
            \Sentry\SentrySdk::getCurrentHub()->setSpan($span);
            self::$spans[] = $span;
        }
    }

    protected function stopSpan($message, $category)
    {
        $currentSpan =  array_pop(self::$spans);
        if ($currentSpan && $currentSpan->getDescription() === $message) {
            $currentSpan->finish();
            $parent = array_pop(self::$spans);
            if (!$parent) {
                $parent = self::$transaction;
            }
            \Sentry\SentrySdk::getCurrentHub()->setSpan($parent);
        }
    }

    protected static function getSentryOpFromCategory($category)
    {
        $categories = [
            'yii\db\Command::query' => 'db.query',
            'yii\db\Connection::open' => 'db.connection',
        ];
        if (isset($categories[$category])) {
            return $categories[$category];
        }
        return $category;
    }
}