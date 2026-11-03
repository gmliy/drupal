<?php

namespace Drupal\xedc_member\Form;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\user\Entity\User;
use Drupal\xedc_member\Service\MemberAliasManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Edits the member's nickname, bio and avatar.
 */
class ProfileForm extends FormBase {

  /**
   * Constructs a ProfileForm.
   */
  public function __construct(
    protected MemberAliasManager $aliasManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('xedc_member.alias_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'xedc_member_profile_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $account = $this->loadAccount();
    if (!$account) {
      return [];
    }

    $nickname = '';
    if ($account->hasField('field_xedc_nickname') && !$account->get('field_xedc_nickname')->isEmpty()) {
      $nickname = $account->get('field_xedc_nickname')->value;
    }
    $bio = '';
    if ($account->hasField('field_xedc_bio') && !$account->get('field_xedc_bio')->isEmpty()) {
      $bio = $account->get('field_xedc_bio')->value;
    }
    $form['#attributes']['class'][] = 'xm-profile-form';
    $form['nickname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('昵称'),
      '#default_value' => $nickname,
      '#maxlength' => 20,
    ];
    $form['bio'] = [
      '#type' => 'textarea',
      '#title' => $this->t('个人简介'),
      '#default_value' => $bio,
      '#rows' => 4,
      '#maxlength' => 300,
    ];
    $form['picture'] = [
      '#type' => 'file',
      '#title' => $this->t('头像'),
      '#title_display' => 'invisible',
      '#id' => 'xm-avatar-input',
      '#wrapper_attributes' => [
        'class' => ['xm-avatar-file-slot', 'visually-hidden'],
      ],
      '#attributes' => [
        'id' => 'xm-avatar-input',
        'accept' => 'image/jpeg,image/png,image/gif,image/webp',
        'class' => ['xm-avatar-file', 'visually-hidden'],
        'tabindex' => '-1',
      ],
    ];
    $uid = (int) $account->id();
    $form['alias'] = [
      '#type' => 'textfield',
      '#title' => $this->t('自定义个人主页'),
      '#default_value' => $this->aliasManager->getSlug($uid),
      '#maxlength' => 32,
      '#size' => 60,
      '#description' => $this->t('会员中心为 /别名，子页面自动使用同一前缀。公开主页为 /r/别名。留空则会员中心使用 /member。可用字母、数字、下划线和连字符。'),
      '#description_display' => 'after',
      '#attributes' => [
        'data-xm-alias' => '1',
        'autocomplete' => 'off',
        'autocapitalize' => 'off',
        'spellcheck' => 'false',
        'placeholder' => 'my-name',
      ],
      '#wrapper_attributes' => [
        'class' => ['xm-alias-field'],
      ],
    ];
    $form['alias_status'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => '',
      '#attributes' => [
        'class' => ['xm-alias-status'],
        'data-xm-alias-status' => '1',
        'aria-live' => 'polite',
      ],
    ];
    $form['#attached']['drupalSettings']['xedcMember']['aliasCheck'] = Url::fromRoute('xedc_member.alias_check')->toString();
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('保存'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('返回会员中心'),
      '#url' => Url::fromRoute('xedc_member.overview'),
      '#attributes' => ['class' => ['xm-btn']],
    ];
    $form['actions']['#prefix'] = '<div class="xm-form-actions">';
    $form['actions']['#suffix'] = '</div>';
    $form['#cache'] = [
      'contexts' => ['user'],
      'max-age' => 0,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $account = $this->loadAccount();
    if (!$account) {
      $form_state->setErrorByName('nickname', $this->t('请先登录后再修改资料。'));
      return;
    }
    if (!$account->hasField('field_xedc_nickname') || !$account->hasField('field_xedc_bio')) {
      $form_state->setErrorByName('nickname', $this->t('个人资料字段尚未配置，请先运行数据库更新。'));
      return;
    }

    $nickname = trim(strip_tags((string) $form_state->getValue('nickname')));
    if (mb_strlen($nickname) > 20) {
      $form_state->setErrorByName('nickname', $this->t('昵称不能超过 20 个字符。'));
    }

    $bio = trim(strip_tags((string) $form_state->getValue('bio')));
    if (mb_strlen($bio) > 300) {
      $form_state->setErrorByName('bio', $this->t('个人简介不能超过 300 个字符。'));
    }

    $alias = $this->aliasManager->check((string) $form_state->getValue('alias'), (int) $account->id());
    if (!$alias['ok']) {
      $form_state->setErrorByName('alias', $alias['status'] === 'taken'
        ? $this->t('该路径已被占用。')
        : $alias['message']);
    }

    $bag = $this->getRequest()->files->get('files');
    $upload = is_array($bag) ? ($bag['picture'] ?? NULL) : NULL;
    if (!$upload instanceof UploadedFile || $upload->getError() === UPLOAD_ERR_NO_FILE) {
      return;
    }

    $directory = 'public://pictures/';
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uploaded = file_save_upload('picture', [
      'FileExtension' => ['extensions' => 'png gif jpg jpeg webp'],
      'FileSizeLimit' => ['fileLimit' => 2 * 1024 * 1024],
      'FileIsImage' => [],
    ], $directory, 0, FileExists::Rename);
    if ($uploaded === FALSE) {
      $form_state->setErrorByName('picture', $this->t('头像上传失败，请使用 JPG/PNG/WebP，且不超过 2MB。'));
      return;
    }
    if ($uploaded instanceof FileInterface) {
      $form_state->set('uploaded_picture', (int) $uploaded->id());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $account = $this->loadAccount();
    if (!$account) {
      $this->messenger()->addError($this->t('请先登录后再修改资料。'));
      return;
    }

    $old_picture_fid = (int) ($account->get('user_picture')->target_id ?? 0);
    $nickname = trim(strip_tags((string) $form_state->getValue('nickname')));
    $bio = trim(strip_tags((string) $form_state->getValue('bio')));
    $account->set('field_xedc_nickname', $nickname);
    $account->set('field_xedc_bio', $bio);

    $fid = (int) $form_state->get('uploaded_picture');
    if ($fid > 0) {
      $file = File::load($fid);
      if ($file instanceof FileInterface) {
        if (!$file->isPermanent()) {
          $file->setPermanent();
          $file->save();
        }
        $account->set('user_picture', ['target_id' => $fid]);
      }
    }

    $account->save();
    $this->deleteUnusedPicture($old_picture_fid, $fid > 0 ? $fid : $old_picture_fid);

    $alias_error = $this->aliasManager->save((int) $account->id(), (string) $form_state->getValue('alias'));
    if ($alias_error) {
      $this->messenger()->addError($alias_error);
      $form_state->setRedirectUrl(Url::fromRoute('xedc_member.profile'));
      return;
    }

    $form_state->setRedirectUrl(Url::fromRoute('xedc_member.profile'));
  }

  /**
   * Deletes a previous avatar file when nothing still references it.
   */
  protected function deleteUnusedPicture(int $old_fid, int $current_fid): void {
    if ($old_fid <= 0 || $old_fid === $current_fid) {
      return;
    }
    $old_file = File::load($old_fid);
    if ($old_file instanceof FileInterface && empty(\Drupal::service('file.usage')->listUsage($old_file))) {
      $old_file->delete();
    }
  }

  /**
   * Loads the current user entity.
   */
  protected function loadAccount() {
    $uid = (int) $this->currentUser()->id();
    return $uid > 0 ? User::load($uid) : NULL;
  }

}
