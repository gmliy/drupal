<?php

declare(strict_types=1);

namespace Drupal\Tests\xedc_auto_translate\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\xedc_auto_translate\Translator;

/**
 * Guards the content translation entry points that UI translation must not change.
 *
 * @group xedc_auto_translate
 */
final class ContentTranslationContractTest extends UnitTestCase {

  /**
   * Content publishing still enters Translator::translateEntity directly.
   */
  public function testContentHookStaysOnTranslateEntity(): void {
    $root = dirname(__DIR__, 3);
    $module = file_get_contents($root . '/xedc_auto_translate.module');
    $services = file_get_contents($root . '/xedc_auto_translate.services.yml');
    $translator = file_get_contents($root . '/src/Translator.php');
    $this->assertIsString($module);
    $this->assertStringContainsString("->translateEntity(\$entity)", $module);
    $this->assertStringContainsString('function xedc_auto_translate_entity_insert', $module);
    $this->assertStringContainsString('function xedc_auto_translate_entity_update', $module);
    $this->assertStringNotContainsString('xedc_ui_locale', $module . $services . $translator);
    $this->assertStringContainsString('xedc_auto_translate.translator:', (string) $services);
    $reflection = new \ReflectionClass(Translator::class);
    $this->assertTrue($reflection->hasMethod('translateEntity'));
    $this->assertTrue($reflection->hasMethod('translatePlainTexts'));
    $types = $reflection->getReflectionConstant('TEXT_TYPES')->getValue();
    $this->assertContains('text_with_summary', $types);
    $this->assertContains('string', $types);
    $skip = $reflection->getReflectionConstant('SKIP_FIELDS')->getValue();
    $this->assertContains('path', $skip);
    $this->assertContains('moderation_state', $skip);
  }

}
