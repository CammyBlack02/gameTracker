<?php

namespace GameTracker\Domain;

final class AccessDeniedException extends DomainException
{
    public function slug(): string
    {
        return 'access_denied';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
