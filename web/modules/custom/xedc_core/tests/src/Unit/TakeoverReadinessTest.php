<?php

declare(strict_types=1);

namespace Drupal\Tests\xedc_core\Unit;

use Drupal\xedc_core\Ops\Storage\TakeoverReadiness;
use PHPUnit\Framework\TestCase;

/**
 * Isolated takeover judgments. None of these inputs touch production files.
 *
 * @group xedc_core
 */
final class TakeoverReadinessTest extends TestCase {

  public function testAllSwitchesOffMatchesLocalMode(): void {
    $result = TakeoverReadiness::assess($this->local());
    $this->assertSame('通过', $result['takeover_check']);
    $this->assertFalse($result['execution_allowed']);
    $this->assertFalse($result['executed_this_round']);
    $this->assertSame('未启用', $result['rows'][0]['state']);
    $this->assertSame('未启用', $result['rows'][1]['state']);
    $this->assertSame('未启用', $result['rows'][2]['state']);
    $this->assertSame('未启用', $result['rows'][3]['state']);
    $this->assertSame('不允许', $result['summary'][2]['result']);
    $this->assertSame('未执行', $result['summary'][3]['result']);
  }

  public function testOnlyRuntimeOnDoesNotPassTakeover(): void {
    $input = $this->production();
    $input['public_takeover'] = FALSE;
    $input['xedc_public_takeover'] = FALSE;
    $input['cdn_rewrite'] = FALSE;
    $input['runtime_cdn_enabled'] = FALSE;
    $result = TakeoverReadiness::assess($input);
    $this->assertSame('不通过', $result['takeover_check']);
    $this->assertSame('通过', $result['rows'][0]['result']);
    $this->assertSame('已启用', $result['rows'][0]['state']);
    $this->assertSame('不通过', $result['rows'][1]['result']);
    $this->assertSame('通过', $result['rows'][2]['result']);
    $this->assertSame('不通过', $result['rows'][3]['result']);
    $this->assertFalse($result['execution_allowed']);
    $this->assertStringNotContainsString('已经打开。本检查预期它们是关闭的', implode("\n", $result['failures']));
  }

  public function testOnlyPublicTakeoverOn(): void {
    $input = $this->local();
    $input['public_takeover'] = TRUE;
    $input['xedc_public_takeover'] = TRUE;
    $result = TakeoverReadiness::assess($input);
    $this->assertSame('不通过', $result['takeover_check']);
    $this->assertSame('通过', $result['rows'][0]['result']);
    $this->assertSame('不通过', $result['rows'][1]['result']);
    $this->assertSame('已启用', $result['rows'][1]['state']);
    $this->assertSame('通过', $result['rows'][2]['result']);
    $this->assertSame('通过', $result['rows'][3]['result']);
  }

  public function testOnlyCdnRewriteOn(): void {
    $input = $this->local();
    $input['cdn_rewrite'] = TRUE;
    $result = TakeoverReadiness::assess($input);
    $this->assertSame('不通过', $result['takeover_check']);
    $this->assertSame('通过', $result['rows'][0]['result']);
    $this->assertSame('通过', $result['rows'][1]['result']);
    $this->assertSame('通过', $result['rows'][2]['result']);
    $this->assertSame('不通过', $result['rows'][3]['result']);
    $this->assertSame('已启用', $result['rows'][3]['state']);
  }

  public function testSeveralSwitchesOnWhileLocal(): void {
    $input = $this->local();
    $input['settings_runtime_applied'] = TRUE;
    $input['public_takeover'] = TRUE;
    $input['xedc_public_takeover'] = TRUE;
    $input['cdn_rewrite'] = TRUE;
    $result = TakeoverReadiness::assess($input);
    $this->assertSame('不通过', $result['takeover_check']);
    $this->assertSame('不通过', $result['rows'][0]['result']);
    $this->assertSame('不通过', $result['rows'][1]['result']);
    $this->assertSame('通过', $result['rows'][2]['result']);
    $this->assertSame('不通过', $result['rows'][3]['result']);
    $this->assertFalse($result['execution_allowed']);
  }

  public function testMissingRuntimeFileStaysNotExecutable(): void {
    $input = $this->local();
    $input['runtime_file'] = 'missing';
    $input['public_mode'] = '';
    $input['settings_public_mode'] = '';
    $result = TakeoverReadiness::assess($input);
    $this->assertSame('uninitialized', $result['phase']);
    $this->assertSame('通过', $result['takeover_check']);
    $this->assertFalse($result['execution_allowed']);
    $this->assertFalse($result['executed_this_round']);
    $this->assertStringContainsString('不执行接管', $result['execution_reason']);
  }

  public function testSettingsReadFailureIsNotTreatedAsOff(): void {
    $result = TakeoverReadiness::assess([
      'settings_readable' => FALSE,
      'public_takeover' => TRUE,
      'cdn_rewrite' => TRUE,
      'public_mode' => 'cloudflare_r2',
    ]);
    $this->assertSame('unreadable', $result['phase']);
    $this->assertSame('不通过', $result['takeover_check']);
    foreach ($result['rows'] as $row) {
      $this->assertSame('无法判断', $row['state']);
      $this->assertSame('不通过', $row['result']);
      $this->assertStringContainsString('配置读取失败', $row['explanation']);
    }
    $this->assertFalse($result['execution_allowed']);
  }

  public function testSettingsDisagreeWithEachOther(): void {
    $input = $this->production();
    $input['xedc_public_takeover'] = FALSE;
    $result = TakeoverReadiness::assess($input);
    $this->assertSame('不通过', $result['rows'][1]['result']);
    $this->assertStringContainsString('不一致', $result['rows'][1]['explanation']);
    $this->assertFalse($result['execution_allowed']);
    $this->assertFalse($result['observed_public_r2']);
  }

  public function testProductionR2IsConsistentWithoutClaimingExecution(): void {
    $result = TakeoverReadiness::assess($this->production());
    $this->assertSame('production_r2', $result['phase']);
    $this->assertSame('通过', $result['takeover_check']);
    $this->assertSame('已启用', $result['rows'][0]['state']);
    $this->assertSame('已启用', $result['rows'][1]['state']);
    $this->assertSame('未启用', $result['rows'][2]['state']);
    $this->assertSame('已启用', $result['rows'][3]['state']);
    foreach ($result['rows'] as $row) {
      $this->assertSame('通过', $row['result'], $row['label']);
      $this->assertNotSame('', $row['source']);
    }
    $this->assertTrue($result['observed_public_r2']);
    $this->assertFalse($result['execution_allowed']);
    $this->assertFalse($result['executed_this_round']);
    $this->assertSame('不允许', $result['summary'][2]['result']);
    $this->assertSame('未执行', $result['summary'][3]['result']);
    $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
    $this->assertIsString($encoded);
    $this->assertStringNotContainsString('本检查预期它们是关闭的', $encoded);
  }

  public function testInvalidRuntimeFileBlocksEverySwitch(): void {
    $input = $this->production();
    $input['runtime_file'] = 'invalid';
    $result = TakeoverReadiness::assess($input);
    $this->assertSame('invalid', $result['phase']);
    $this->assertSame('不通过', $result['takeover_check']);
    foreach ($result['rows'] as $row) {
      $this->assertSame('无法判断', $row['state']);
      $this->assertSame('不通过', $row['result']);
    }
    $this->assertFalse($result['execution_allowed']);
    $this->assertFalse($result['observed_public_r2']);
  }

  public function testHealthyAdapterDoesNotMakeTakeoverExecutable(): void {
    $input = $this->production();
    $input['private_takeover'] = TRUE;
    $input['xedc_private_takeover'] = TRUE;
    $input['adapters_ok'] = TRUE;
    $result = TakeoverReadiness::assess($input);
    $this->assertSame('不通过', $result['takeover_check']);
    $this->assertSame('不通过', $result['rows'][2]['result']);
    $this->assertFalse($result['execution_allowed']);
    $this->assertFalse($result['executed_this_round']);
  }

  public function testPassedCheckIsNotAnExecutedTakeover(): void {
    $result = TakeoverReadiness::assess($this->production());
    $this->assertSame('通过', $result['takeover_check']);
    $this->assertFalse($result['executed_this_round']);
    $this->assertFalse($result['execution_allowed']);
    $this->assertStringContainsString('没有执行', $result['execution_reason']);
  }

  /**
   * @return array<string, mixed>
   *   Local mode with every effective switch off.
   */
  private function local(): array {
    return [
      'settings_readable' => TRUE,
      'runtime_file' => 'ok',
      'public_mode' => 'local',
      'public_provider' => 'local',
      'settings_public_mode' => 'local',
      'settings_runtime_applied' => FALSE,
      'public_takeover' => FALSE,
      'xedc_public_takeover' => FALSE,
      'private_takeover' => FALSE,
      'xedc_private_takeover' => FALSE,
      'cdn_rewrite' => FALSE,
      'runtime_cdn_enabled' => FALSE,
      'public_wrapper_remote' => FALSE,
      'private_wrapper_remote' => FALSE,
      'public_wrapper_class' => 'Drupal\\xedc_core\\StreamWrapper\\XedcPublicS3fsStream',
      'private_wrapper_class' => 'Drupal\\Core\\StreamWrapper\\PrivateStream',
    ];
  }

  /**
   * @return array<string, mixed>
   *   Current production shape, without reading the live file.
   */
  private function production(): array {
    $input = $this->local();
    $input['public_mode'] = 'cloudflare_r2';
    $input['public_provider'] = 'silly';
    $input['settings_public_mode'] = 'cloudflare_r2';
    $input['public_takeover'] = TRUE;
    $input['xedc_public_takeover'] = TRUE;
    $input['cdn_rewrite'] = TRUE;
    $input['runtime_cdn_enabled'] = TRUE;
    $input['public_wrapper_remote'] = TRUE;
    return $input;
  }

}
