<?php

namespace App\Support;

/**
 * Per-process request id, filled by the RequestId middleware and read by
 * the logging processor so every log line can be correlated to a request.
 */
class RequestContext
{
    private ?string $requestId = null;

    public function setId(string $id): void
    {
        $this->requestId = $id;
    }

    public function id(): ?string
    {
        return $this->requestId;
    }

    public function clear(): void
    {
        $this->requestId = null;
    }
}
