<?php

declare(strict_types=1);

namespace Drupal\Tests\xedc_core\Unit;

use Drupal\xedc_core\Ops\Storage\CachePolicy;
use Drupal\xedc_core\Ops\Storage\CdnUrlPolicy;
use PHPUnit\Framework\TestCase;

/**
 * CDN address and cache rules that do not boot Drupal.
 *
 * @group xedc_core
 */
final class CdnDeliveryPolicyTest extends TestCase {

  private const ENDPOINT = 'https://409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com';

  public function testLocalPublicFileIsNotRewritten(): void {
    $this->assertNull($this->decide(TRUE, FALSE, FALSE, 's3fs-public/photo.jpg'));
    $this->assertNull($this->decide(FALSE, FALSE, FALSE, 's3fs-public/photo.jpg'));
  }

  public function testPublicRemoteFileUsesTheCdnHost(): void {
    $url = $this->decide(TRUE, TRUE, FALSE, 's3fs-public/2026/photo.jpg');
    $this->assertSame('https://cdn.gitxx.xyz/s3fs-public/2026/photo.jpg', $url);
  }

  public function testPrivateRemoteFileNeverUsesTheCdn(): void {
    $this->assertNull($this->decide(TRUE, TRUE, TRUE, 's3fs-private/member.zip'));
    $this->assertNull($this->decide(TRUE, TRUE, FALSE, 's3fs-private/member.zip'));
    $this->assertTrue(CdnUrlPolicy::rejectedUrl(
      'https://cdn.gitxx.xyz/s3fs-private/member.zip',
      's3fs-private',
      self::ENDPOINT,
    ));
  }

  public function testCdnSwitchOffKeepsTheOriginalAddress(): void {
    $this->assertNull($this->decide(FALSE, TRUE, FALSE, 's3fs-public/photo.jpg'));
  }

  public function testApiEndpointIsNotACdn(): void {
    $this->assertNull(CdnUrlPolicy::decide(
      TRUE,
      TRUE,
      FALSE,
      's3fs-public/photo.jpg',
      's3fs-public',
      's3fs-private',
      self::ENDPOINT,
      self::ENDPOINT,
    ));
    $this->assertNull(CdnUrlPolicy::decide(
      TRUE,
      TRUE,
      FALSE,
      's3fs-public/photo.jpg',
      's3fs-public',
      's3fs-private',
      'https://dclili.r2.cloudflarestorage.com',
      self::ENDPOINT,
    ));
  }

  public function testUnknownUriIsRejected(): void {
    $this->assertNull($this->decide(TRUE, TRUE, FALSE, NULL));
    $this->assertNull($this->decide(TRUE, TRUE, FALSE, 'other/photo.jpg'));
    $this->assertNull($this->decide(TRUE, FALSE, FALSE, NULL));
  }

  public function testImageStyleAndDownloadKeys(): void {
    $style = $this->decide(TRUE, TRUE, FALSE, 's3fs-public/styles/thumbnail/public/photo.jpg');
    $this->assertSame('https://cdn.gitxx.xyz/s3fs-public/styles/thumbnail/public/photo.jpg', $style);
    $this->assertNull($this->decide(FALSE, FALSE, FALSE, 's3fs-public/styles/thumbnail/public/photo.jpg'));
    $this->assertNull($this->decide(TRUE, TRUE, TRUE, 's3fs-private/styles/thumbnail/private/secret.jpg'));
    $this->assertNull($this->decide(TRUE, TRUE, TRUE, 's3fs-private/downloads/member.zip'));
  }

  public function testMissingCdnConfigurationDoesNotThrow(): void {
    $this->assertNull(CdnUrlPolicy::decide(TRUE, TRUE, FALSE, 's3fs-public/photo.jpg', 's3fs-public', 's3fs-private', '', self::ENDPOINT));
  }

  public function testUnversionedProductionNamesAreNotImmutable(): void {
    $this->assertFalse(CachePolicy::versioned('s3fs-public/2026/photo.jpg'));
    $this->assertSame(CachePolicy::PUBLIC_REVALIDATE, CachePolicy::forObject('s3fs-public/2026/photo.jpg', FALSE));
    $this->assertSame(CachePolicy::IMMUTABLE, CachePolicy::forObject('s3fs-public/2026/photo.0123456789abcdef.jpg', FALSE));
    $this->assertSame(CachePolicy::PRIVATE, CachePolicy::forObject('s3fs-private/member.zip', TRUE));
    $this->assertSame('photo.abababababababab.jpg', CachePolicy::versionedFilename('photo.jpg', str_repeat('ab', 32)));
    $this->assertNull(CachePolicy::versionedFilename('photo.jpg', 'short'));
  }

  private function decide(bool $rewrite, bool $remote, bool $private, ?string $key): ?string {
    return CdnUrlPolicy::decide(
      $rewrite,
      $remote,
      $private,
      $key,
      's3fs-public',
      's3fs-private',
      CdnUrlPolicy::PRODUCTION_BASE,
      self::ENDPOINT,
    );
  }

}
