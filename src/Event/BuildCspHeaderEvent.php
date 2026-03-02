<?php

declare(strict_types=1);

namespace MulerTech\CspBundle\Event;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

final class BuildCspHeaderEvent extends Event
{
    public const string NAME = 'mulertech_csp.build_header';

    private ?string $headerValue = null;

    public function __construct(
        private readonly Request $request,
    ) {
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getHeaderValue(): ?string
    {
        return $this->headerValue;
    }

    public function setHeaderValue(string $value): void
    {
        $this->headerValue = $value;
    }
}
