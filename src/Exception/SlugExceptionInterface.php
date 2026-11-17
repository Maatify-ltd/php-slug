<?php

declare(strict_types=1);

namespace Maatify\Slug\Exception;

/** Identifies package-defined Slug exceptions. Propagated PDO and other infrastructure errors do not implement this marker. */
interface SlugExceptionInterface extends \Throwable {}
