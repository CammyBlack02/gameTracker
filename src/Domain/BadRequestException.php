<?php

namespace GameTracker\Domain;

final class BadRequestException extends DomainException
{
    public function slug(): string
    {
        return 'bad_request';
    }

    public function httpStatus(): int
    {
        return 400;
    }
}
