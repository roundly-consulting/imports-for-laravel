<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use RuntimeException;

/**
 * The base of every exception the package throws, so a host can catch them all at once.
 */
abstract class ImportsException extends RuntimeException {}
