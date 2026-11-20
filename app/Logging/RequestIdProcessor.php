<?php

namespace App\Logging;

use App\Support\RequestContext;
use Monolog\LogRecord;

/**
 * Attach the current request id to every log record as `extra.request_id`.
 */
class RequestIdProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $id = app(RequestContext::class)->id();
        if ($id === null) {
            return $record;
        }

        return $record->with(extra: array_merge($record->extra, ['request_id' => $id]));
    }
}
