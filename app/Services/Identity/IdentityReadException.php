<?php

declare(strict_types=1);

namespace App\Services\Identity;

/** A reading that could not be done; the message is a short code (disabled, unreachable, http_<status>, no_result, too_large, limit), never the document. */
final class IdentityReadException extends \RuntimeException {}
