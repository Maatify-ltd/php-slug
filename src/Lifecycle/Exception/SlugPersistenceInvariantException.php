<?php

declare(strict_types=1);

namespace Maatify\Slug\Lifecycle\Exception;

use Maatify\Slug\Exception\SlugSystemException;

/** Signals that persisted state violates a Slug storage invariant, such as a missing claim or an invalid replay snapshot. */
final class SlugPersistenceInvariantException extends SlugSystemException {}
