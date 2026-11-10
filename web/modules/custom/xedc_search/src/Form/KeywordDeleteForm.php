<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_search\Repository\KeywordRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms deletion of an admin keyword.
 */
final class KeywordDeleteForm extends ConfirmFormBase {

  private int $id = 0;

  private string $keyword = '';

  public function __construct(
    private readonly KeywordRepository $keywords,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_search.keywords'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_search_keyword_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $row = $this->keywords->load((int) $id);
    if (!$row) {
      $this->messenger()->addError($this->t('搜索词不存在。'));
      $form_state->setRedirect('xedc_search.admin.keywords');
      return $form;
    }
    $this->id = (int) $row['id'];
    $this->keyword = (string) $row['keyword'];
    $form['#attributes']['class'][] = 'xedc-search-admin';
    $form['#attached']['library'][] = 'xedc_search/admin';
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('删除搜索词「@keyword」？', ['@keyword' => $this->keyword]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('xedc_search.admin.keywords');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->id) {
      $this->keywords->delete($this->id);
      $this->messenger()->addStatus($this->t('已删除搜索词。'));
    }
    $form_state->setRedirect('xedc_search.admin.keywords');
  }

}
