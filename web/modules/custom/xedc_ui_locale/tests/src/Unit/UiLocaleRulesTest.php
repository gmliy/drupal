<?php

declare(strict_types=1);

namespace Drupal\Tests\xedc_ui_locale\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\xedc_ui_locale\InterfaceStringExtractor;
use Drupal\xedc_ui_locale\ProtectedTermGuard;
use Drupal\xedc_ui_locale\TranslationValidator;
use Drupal\xedc_ui_locale\UiLocale;

/**
 * Unit coverage for interface-string protection and extraction.
 *
 * @group xedc_ui_locale
 */
final class UiLocaleRulesTest extends UnitTestCase {

  /**
   * The fixture exposes menu, permission, route and PHP interface strings.
   */
  public function testExtractorFindsInterfaceStrings(): void {
    $extractor = new InterfaceStringExtractor();
    $root = dirname(__DIR__, 2) . '/fixtures/ui_sample';
    $found = [];
    foreach ($extractor->extractDirectory($root) as $item) {
      $found[$item['context'] . "\n" . $item['source']] = $item['source'];
    }
    $this->assertContains('Save configuration', $found);
    $this->assertContains('Sample menu', $found);
    $this->assertContains('Administer UI sample', $found);
    $this->assertContains('Sample page', $found);
    $this->assertContains('Refresh preview', $found);
    $this->assertArrayHasKey("Greeting\nHello @name", $found);
    $this->assertNotContains('ui_sample.page', $found);
    $meta = $extractor->readMeta($root, 'ui_sample');
    $this->assertSame('UI Sample', $meta['label']);
    $this->assertSame('drupal/ui_sample', $meta['package']);
    $this->assertNotContains('UI Sample', $found);
  }

  /**
   * Placeholders, HTML and the project name survive validation.
   */
  public function testValidatorAndProtection(): void {
    $guard = new ProtectedTermGuard($this->getConfigFactoryStub([
      'xedc_ui_locale.settings' => ['protected_terms' => ['DcLili', 'Drupal']],
    ]));
    $validator = new TranslationValidator();
    $terms = $guard->termsForModule(['UI Sample', 'ui_sample', 'drupal/ui_sample']);
    $this->assertTrue($guard->isExactTerm('UI Sample', $terms));
    $this->assertTrue($guard->isExactTerm('DcLili', $terms));
    $this->assertFalse($guard->isExactTerm('Save configuration', $terms));
    [$shielded, $map] = $guard->shield('Save the DcLili @name', $terms);
    $this->assertStringNotContainsString('DcLili', $shielded);
    $this->assertStringNotContainsString('@name', $shielded);
    $this->assertSame('保存 DcLili @name', $guard->unshield(str_replace('Save the', '保存', $shielded), $map));
    $this->assertNull($validator->validate('Save @name', '保存 @name', $terms, $guard));
    $this->assertSame('占位符丢失：@name', $validator->validate('Save @name', '保存', $terms, $guard));
    $this->assertSame('HTML 结构不一致', $validator->validate('Read <em>this</em>', '阅读 this', $terms, $guard));
    $plural = '1 item' . UiLocale::PLURAL . '@count items';
    $this->assertNull($validator->validate($plural, '1 项' . UiLocale::PLURAL . '@count 项', $terms, $guard));
  }

}
