<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Drupal\xedc_tags\TagAudit;
use Drupal\xedc_tags\TagGuard;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deletes one tag after reference checks. There is no bulk delete.
 */
final class DeleteTermForm extends ConfirmFormBase {

  private ?TermInterface $term = NULL;

  public function __construct(
    private TagGuard $guard,
    private TagAudit $audit,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.guard'),
      $container->get('xedc_tags.audit'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_delete';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?TermInterface $taxonomy_term = NULL): array {
    $this->term = $taxonomy_term;
    $form = parent::buildForm($form, $form_state);
    $form['#attached']['library'][] = 'xedc_tags/admin';
    if (!$taxonomy_term) {
      return $form;
    }
    $tid = (int) $taxonomy_term->id();
    $blockers = $this->guard->blockers($tid);
    $content = $this->guard->contentReferences($tid);
    $items = [];
    foreach ($content as $group) {
      $items[] = $group['entity_type'] . '.' . $group['field'] . '：' . count($group['ids']);
    }
    foreach ($blockers as $blocker) {
      $items[] = $blocker['label'];
    }
    $languages = array_keys($taxonomy_term->getTranslationLanguages());
    if (count($languages) > 1) {
      $items[] = $this->t('这个标签有翻译：@langs。删除会同时删除这些翻译。', ['@langs' => implode(', ', $languages)]);
      $form['confirm_translations'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('我确认同时删除全部翻译'),
        '#required' => TRUE,
      ];
    }
    $form['refs'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('删除前检查'),
      '#items' => $items ?: [$this->t('没有发现内容、菜单或跳转引用。')],
    ];
    if ($content || $blockers) {
      unset($form['actions']['submit']);
      $form['blocked'] = [
        '#markup' => '<p>' . $this->t('仍有引用，不能删除。') . '</p>',
      ];
    }
    return $form;
  }

  public function getQuestion(): string {
    return (string) $this->t('删除标签“@name”？', ['@name' => $this->term ? $this->term->label() : '']);
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('xedc_tags.admin_unused');
  }

  public function getConfirmText(): string {
    return (string) $this->t('确认删除');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $term = $this->term;
    if (!$term) {
      $term = $form_state->get('term');
    }
    if (!$term instanceof TermInterface) {
      $this->messenger()->addError($this->t('标签不存在。'));
      return;
    }
    $tid = (int) $term->id();
    if ($this->guard->contentCount($tid) > 0 || $this->guard->blockers($tid)) {
      $this->messenger()->addError($this->t('仍有引用，没有删除。'));
      return;
    }
    $name = $term->label();
    $term->delete();
    $this->audit->log('delete', '删除标签 ' . $name, [], $tid);
    $this->messenger()->addStatus($this->t('已删除标签 @name。', ['@name' => $name]));
    $form_state->setRedirect('xedc_tags.admin_unused');
  }

}
