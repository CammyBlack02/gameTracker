<?php

namespace GameTracker\Domain;

final class NotFoundException extends DomainException
{
    public function slug(): string
    {
        return 'not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
