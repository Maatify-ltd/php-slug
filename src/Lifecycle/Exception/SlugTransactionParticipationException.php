<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Exception;

use Maatify\Slug\Exception\SlugUnsupportedException;

/**
 * Signals failure to establish, complete, or clean up a package transaction or savepoint.
 * A failure may occur before or after the mutation callback has run.
 */
final class SlugTransactionParticipationException extends SlugUnsupportedException {}
