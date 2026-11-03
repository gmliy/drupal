<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Ops\Storage\Provider;

/**
 * A storage backend the manager can describe and check.
 *
 * Implementations must not generate public URLs from an API endpoint, rewrite
 * private URIs, or delete objects outside an explicit test prefix.
 */
interface StorageProviderInterface {

  public function getId(): string;

  public function getLabel(): string;

  /**
   * Stable type id such as local or cloudflare_r2.
   */
  public function getType(): string;

  /**
   * TRUE only when this process can use the backend, not merely list it.
   */
  public function isOperational(): bool;

  /**
   * @return array<string, bool>
   *   Capability name to whether this adapter implements it.
   */
  public function capabilities(): array;

  /**
   * @return array<string, string>
   *   Display fields. No credentials.
   */
  public function summary(): array;

  /**
   * @return array{status: string, detail: string}
   *   Read-only health. Status is ok, warn, bad, or unsupported.
   */
  public function healthReadOnly(): array;

}
