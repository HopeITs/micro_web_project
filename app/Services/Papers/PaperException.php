<?php

declare(strict_types=1);

namespace App\Services\Papers;

/** A refused change to the papers library; the message is a language key the controller shows to the person. */
final class PaperException extends \RuntimeException {}
