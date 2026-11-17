<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Exception;

use Maatify\Slug\Exception\SlugUnsupportedException;

/** Rejects a lifecycle mutation before it runs when the connection cannot participate in the Host transaction using a savepoint. */
final class SlugTransactionParticipationException extends SlugUnsupportedException {}
