<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Cleanup;

/**
 * Stops a merge before it is finished and keeps saves that already succeeded.
 */
class RecoverableMergeException extends \RuntimeException {}
