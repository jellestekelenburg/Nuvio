<?php

namespace App\Exceptions;

use RuntimeException;

class CorruptFileTreeException extends RuntimeException
{
    public static function forUser(int $userId): self
    {
        return new self(
            "The file tree for user {$userId} is corrupt. Permanent deletion was stopped before storage was modified.",
        );
    }
}
