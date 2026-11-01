<?php

declare(strict_types=1);

namespace Drupal\xedc_core;

/**
 * Stops the whole import. The current image is left pending and its temp file stays.
 */
class ImportAbortException extends ImportException {}
