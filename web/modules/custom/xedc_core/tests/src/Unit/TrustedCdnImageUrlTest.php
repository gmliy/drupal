<?php

declare(strict_types=1);

namespace Drupal\Tests\xedc_core\Unit;

use Drupal\xedc_core\Filter\TrustedCdnImageUrl;
use Drupal\xedc_core\Ops\Storage\CdnUrlPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Host and path rules for body images on the public CDN.
 *
 * @group xedc_core
 */
final class TrustedCdnImageUrlTest extends TestCase {

  public function testProductionCdnHostIsTheOnlyAllowedHost(): void {
    $this->assertSame(TrustedCdnImageUrl::HOST, parse_url(CdnUrlPolicy::PRODUCTION_BASE, PHP_URL_HOST));
    $this->assertTrue(TrustedCdnImageUrl::isTrusted('https://cdn.gitxx.xyz/public/test.webp'));
    $this->assertTrue(TrustedCdnImageUrl::isTrusted('https://CDN.GITXX.XYZ/public/inline-images/body.webp'));
    $this->assertTrue(TrustedCdnImageUrl::isTrusted('https://cdn.gitxx.xyz:443/public/test.webp'));
  }

  public function testUntrustedHostsAndDisguisesAreRejected(): void {
    $rejected = [
      'https://evil.example/a.png',
      'https://cdn.gitxx.xyz.attacker.example/public/a.webp',
      'https://attacker.example/cdn.gitxx.xyz/public/a.webp',
      'https://evil.cdn.gitxx.xyz/public/a.webp',
      'https://cdn.gitxx.xyz.attacker.example/public/a.webp',
      'https://cdn.gitxx.xyzz/public/a.webp',
      'https://user:pass@cdn.gitxx.xyz/public/a.webp',
      'https://cdn.gitxx.xyz:444/public/a.webp',
      'https://cdn.gitxx.xyz./public/a.webp',
      'http://cdn.gitxx.xyz/public/a.webp',
      'http://evil.example/a.png',
      '//cdn.gitxx.xyz/public/a.webp',
      'javascript:alert(1)',
      'data:image/png;base64,aaaa',
      'https://cdn.gitxx.xyz/private/secret.webp',
      'https://cdn.gitxx.xyz/public/../private/secret.webp',
      'https://cdn.gitxx.xyz/public/%2e%2e/private/secret.webp',
      'https://cdn.gitxx.xyz/public/%252e%252e/private/secret.webp',
      'https://cdn.gitxx.xyz%2eattacker.example/public/a.webp',
      'https://cdn.gitxx.xyz%00.evil.example/public/a.webp',
      'https://cdn.gitxx.xyz\\@evil.example/public/a.webp',
      'https://cdn.gitxx.xyz/public/',
      '',
    ];
    foreach ($rejected as $url) {
      $this->assertFalse(TrustedCdnImageUrl::isTrusted($url), $url);
    }
  }

}
