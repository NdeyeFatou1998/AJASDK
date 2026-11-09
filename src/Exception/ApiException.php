<?php

declare(strict_types=1);

namespace Aja\Exception;

/** AJA answered with an error envelope: {error:{code,message,details},request_id}. */
class ApiException extends AjaException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        private readonly int $httpStatus,
        private readonly string $errorCode,
        string $message,
        private readonly ?string $requestId = null,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** Catalogue code, e.g. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Correlation id to quote when reporting a problem to AJA support. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * @return array<string,mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
