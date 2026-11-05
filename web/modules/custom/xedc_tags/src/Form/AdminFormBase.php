<?php

declare(strict_types=1);

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Url;

/**
 * Shared admin page chrome for tag forms.
 */
abstract class AdminFormBase extends FormBase {

  /**
   * Wraps the form in the shared Gin + XEDC admin shell.
   *
   * @param array<string, mixed> $form
   * @param array{title?: string|\Drupal\Core\StringTranslation\TranslatableMarkup, description?: string|\Drupal\Core\StringTranslation\TranslatableMarkup, show_back?: bool} $meta
   *
   * @return array<string, mixed>
   */
  protected function chrome(array $form, array $meta = []): array {
    $form['#attached']['library'][] = 'xedc_tags/admin';
    $form['#attributes']['class'][] = 'xt-admin';
    $form['#attributes']['class'][] = 'xt-admin--form';

    $title = (string) ($meta['title'] ?? '');
    $description = (string) ($meta['description'] ?? '');
    $showBack = $meta['show_back'] ?? TRUE;

    $header = '<header class="xt-admin__header">';
    $header .= '<div class="xt-admin__header-main">';
    $header .= '<p class="xt-admin__eyebrow">' . $this->t('标签统计') . '</p>';
    if ($title !== '') {
      $header .= '<h1 class="xt-admin__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    }
    if ($description !== '') {
      $header .= '<p class="xt-admin__lede">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $header .= '</div>';
    if ($showBack) {
      $home = Url::fromRoute('xedc_tags.admin_overview')->toString();
      $header .= '<a class="xt-admin__back" href="' . $home . '">' . $this->t('返回总览') . '</a>';
    }
    $header .= '</header>';

    $form['chrome_header'] = [
      '#weight' => -100,
      '#markup' => $header,
    ];
    $form['#cache']['contexts'][] = 'user.permissions';
    $form['#cache']['contexts'][] = 'url.query_args';
    return $form;
  }

  /**
   * Info / warning callout used under page chrome.
   */
  protected function callout(string $text, string $tone = 'info'): array {
    $tone = preg_replace('/[^a-z]/', '', $tone) ?: 'info';
    return [
      '#markup' => '<div class="xt-callout xt-callout--' . $tone . '" role="note"><p>' . $text . '</p></div>',
    ];
  }

  /**
   * Surface panel for form fields or secondary content.
   *
   * Child input elements keep root-level #parents so existing getValue()
   * keys stay stable (visual nesting only).
   *
   * @param array<string, mixed> $children
   * @param string[] $extraClasses
   *
   * @return array<string, mixed>
   */
  protected function panel(array $children, array $extraClasses = []): array {
    $classes = array_merge(['xt-panel'], $extraClasses);
    $panel = [
      '#type' => 'container',
      '#attributes' => ['class' => $classes],
    ];
    foreach ($children as $key => $child) {
      $panel[$key] = $this->rootParents($child, (string) $key);
    }
    return $panel;
  }

  /**
   * @param array<string, mixed> $element
   *
   * @return array<string, mixed>
   */
  private function rootParents(array $element, string $key): array {
    $type = (string) ($element['#type'] ?? '');
    // Nested structural wrappers keep their own children; force leaf inputs to root.
    if (in_array($type, ['container', 'actions', 'fieldset', 'details'], TRUE)) {
      foreach ($element as $childKey => $child) {
        if (is_string($childKey) && $childKey !== '' && $childKey[0] !== '#' && is_array($child)) {
          $element[$childKey] = $this->rootParents($child, (string) $childKey);
        }
      }
      return $element;
    }
    if ($type !== '' || isset($element['#markup']) || isset($element['#plain_text'])) {
      if (!isset($element['#parents']) && $type !== '' && $type !== 'submit') {
        $element['#parents'] = [$key];
      }
      // Submit buttons still need unique #name; keep default parents but name is set by Form API.
      if ($type === 'submit' && !isset($element['#parents'])) {
        $element['#parents'] = [$key];
      }
    }
    return $element;
  }

  /**
   * Card + horizontal scroll wrapper around a Drupal table / tableselect.
   *
   * @param array<string, mixed> $table
   *
   * @return array<string, mixed>
   */
  protected function tableCard(array $table, bool $tree = FALSE): array {
    $wrap = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xt-card', 'xt-card--flush', 'xt-table-wrap']],
      'table' => $table,
    ];
    if ($tree) {
      $wrap['#tree'] = TRUE;
    }
    return $wrap;
  }

  protected function redirectTo(string $route, array $query = []): void {
  }

  protected function date(int $timestamp): string {
    if ($timestamp <= 0) {
      return '—';
    }
    return \Drupal::service('date.formatter')->format($timestamp, 'custom', 'Y-m-d H:i');
  }

  /**
   * Renders a low-saturation status badge.
   */
  protected function badge(string $label, string $tone = 'neutral'): string {
    $tone = preg_replace('/[^a-z]/', '', $tone) ?: 'neutral';
    return '<span class="xt-badge xt-badge--' . $tone . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
  }

  /**
   * Tag name with secondary Term ID.
   */
  protected function tagNameMarkup(string $name, int $tid): string {
    return '<span class="xt-tag-name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>'
      . ' <span class="xt-tag-tid">#' . $tid . '</span>';
  }

}
