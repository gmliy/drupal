<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Review;

/**
 * Builds a text-only prompt. It has no image or video payload.
 */
final class PromptBuilder {

  /**
   * @param array<string, mixed> $fields
   *   Extracted text.
   * @param array<string, mixed> $local
   *   Local findings.
   * @param bool $submission
   *   Whether the author is a member.
   *
   * @return list<array{role: string, content: string}>
   *   Chat messages.
   */
  public function messages(array $fields, array $local, string $depth, bool $submission): array {
    if ($depth === 'translation') {
      $langcode = (string) ($fields['target_lang'] ?? 'en');
      $system = '你是翻译编辑。只根据给出的文字提供 ' . $langcode . ' 译文建议，不能声称已经写入网站。不要判断图片或视频画面。只输出一个 JSON 对象，不要使用 Markdown。键为 summary、items（对象数组，每项含 field、original、suggested、langcode）。';
    }
    elseif ($depth === 'seo') {
      $system = '你是 SEO 编辑。只能根据给出的文字提建议，不能声称已经修改网站。不要判断图片或视频画面。只输出一个 JSON 对象，不要使用 Markdown。键为 seo_title、meta_description、tags（字符串数组）、stuffing（true 或 false）、relevance、summary、issues（字符串数组）、suggestions（字符串数组）。可以另给标题、简介、图片 alt、标签和分类建议，但不要输出 JSON 以外的内容。';
    }
    else {
      $system = '你是网站文字审核员。只能根据给出的文字判断，不能把图片或视频说成画面违规。只输出一个 JSON 对象，不要使用 Markdown。键为 status（pass、attention、fail 或 review_required）、risk_level（normal、low、medium 或 high）、score（0 到 100 的整数）、summary、issues（对象数组，每项含 type、severity、field、message、suggestion）、suggestions（对象数组，每项含 field、original、suggested、reason）。也可以用字符串数组，并额外给出 seo_score。';
      if ($depth === 'deep') {
        $system .= ' 建议写得具体一些，但不要输出 JSON 以外的内容。';
      }
    }
    return [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user', 'content' => $this->userText($fields, $local, $depth, $submission)],
    ];
  }

  /**
   * @param array<string, mixed> $fields
   *   Extracted text.
   * @param array<string, mixed> $local
   *   Local findings.
   */
  private function userText(array $fields, array $local, string $depth, bool $submission): string {
    $limit = $depth === 'deep' || $depth === 'seo' ? 8000 : 4000;
    $body = (string) ($fields['body'] ?? '');
    $truncated = FALSE;
    if (mb_strlen($body) > $limit) {
      $body = mb_substr($body, 0, $limit);
      $truncated = TRUE;
    }
    $bundle = (string) ($fields['bundle'] ?? '');
    $focus = match ($bundle) {
      'article' => '检查标题是否合理、正文是否完整、垃圾内容、重复文本、关键词堆砌、内容质量、可疑违规文字、简介是否匹配标题和正文、明显 SEO 问题。',
      'photo' => '这是图片内容的文字元数据。检查标题、简介、分类、重复或不相关标签、垃圾关键词和明显 SEO 问题。不要评价画面。',
      'video' => '这是视频内容的文字元数据。检查标题、简介、分类、标签和垃圾信息。不要评价画面，也不要假设视频内容。',
      default => '只检查给出的文字。',
    };
    if ($submission) {
      $focus .= ' 这是会员投稿。额外检查标题和描述质量、垃圾投稿、重复文字、是否符合常规网站内容规范，以及是否需要人工重点检查。';
    }
    $lines = [
      '内容类型：' . $bundle,
      '检查重点：' . $focus,
      '标题：' . (string) ($fields['title'] ?? ''),
      '简介：' . (string) ($fields['summary'] ?? ''),
      '正文：' . $body,
      '分类：' . implode('、', (array) ($fields['categories'] ?? [])),
      '标签：' . implode('、', (array) ($fields['tags'] ?? [])),
      '人物：' . implode('、', (array) ($fields['people'] ?? [])),
      '其他文字：' . (string) ($fields['extra'] ?? ''),
    ];
    if ($truncated) {
      $lines[] = '正文已截断。只评价给出的部分。';
    }
    $localLines = [];
    foreach ((array) ($local['text'] ?? []) as $issue) {
      if (is_array($issue) && isset($issue['message'])) {
        $localLines[] = (string) $issue['message'];
      }
    }
    foreach ((array) ($local['files'] ?? []) as $file) {
      if (!is_array($file)) {
        continue;
      }
      $localLines[] = trim(($file['kind'] ?? '') . ' ' . ($file['mime'] ?? '') . ' ' . ($file['width'] ?? 0) . 'x' . ($file['height'] ?? 0));
      foreach ((array) ($file['messages'] ?? []) as $message) {
        $localLines[] = (string) $message;
      }
    }
    $lines[] = '本地程序检查（不是视觉模型）：' . ($localLines === [] ? '无' : implode('；', $localLines));
    return implode("\n", $lines);
  }

}
