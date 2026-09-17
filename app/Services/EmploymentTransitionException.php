<?php

declare(strict_types=1);

namespace App\Services;

/** Illegal employment transition; the message is safe to show to the user. */
final class EmploymentTransitionException extends \RuntimeException {}
