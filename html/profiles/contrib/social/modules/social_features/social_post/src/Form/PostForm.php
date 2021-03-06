<?php

namespace Drupal\social_post\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for Post edit forms.
 *
 * @ingroup social_post
 */
class PostForm extends ContentEntityForm {

  private $postViewDefault;
  private $postViewProfile;
  private $postViewGroup;

  /**
   * The Current User object.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a NodeForm object.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(TimeInterface $time = NULL, AccountInterface $current_user) {
    $this->time = $time ?: \Drupal::service('datetime.time');
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('datetime.time'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'social_post_entity_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Init form modes.
    $this->setFormMode();

    $display = $this->getFormDisplay($form_state);
    $form = parent::buildForm($form, $form_state);
    $form['#attached']['library'][] = 'social_post/keycode-submit';

    if (isset($form['field_visibility'])) {
      $form['#attached']['library'][] = 'social_post/visibility-settings';

      // Default is create/add mode.
      $form['field_visibility']['widget'][0]['#edit_mode'] = FALSE;

      if (isset($display)) {
        $this->setFormDisplay($display, $form_state);
      }
      else {
        $visibility_value = $this->entity->get('field_visibility')->value;
        $display_id = ($visibility_value === '0') ? $this->postViewProfile : $this->postViewDefault;
        $display = EntityFormDisplay::load($display_id);
        // Set the custom display in the form.
        $this->setFormDisplay($display, $form_state);
      }

      if (isset($display) && ($display_id = $display->get('id'))) {
        if ($display_id === $this->postViewDefault) {
          // Set default value to community.
          unset($form['field_visibility']['widget'][0]['#options'][0]);

          if (isset($form['field_visibility']['widget'][0]['#default_value'])) {
            $default_value = $form['field_visibility']['widget'][0]['#default_value'];

            if ((string) $default_value !== '1') {
              $form['field_visibility']['widget'][0]['#default_value'] = '2';
            }
          }
          else {
            $form['field_visibility']['widget'][0]['#default_value'] = '2';
          }

          unset($form['field_visibility']['widget'][0]['#options'][3]);
        }
        else {
          $form['field_visibility']['widget'][0]['#default_value'] = "0";
          unset($form['field_visibility']['widget'][0]['#options'][2]);

          $current_group = _social_group_get_current_group();
          if (!$current_group) {
            unset($form['field_visibility']['widget'][0]['#options'][3]);
          }
          else {
            $group_type_id = $current_group->getGroupType()->id();
            $allowed_options = social_group_get_allowed_visibility_options_per_group_type($group_type_id);
            if ($allowed_options['community'] !== TRUE) {
              unset($form['field_visibility']['widget'][0]['#options'][0]);
            }
            if ($allowed_options['public'] !== TRUE) {
              unset($form['field_visibility']['widget'][0]['#options'][1]);
            }
            else {
              $form['field_visibility']['widget'][0]['#default_value'] = "1";
            }
            if ($allowed_options['group'] !== TRUE) {
              unset($form['field_visibility']['widget'][0]['#options'][3]);
            }
            else {
              $form['field_visibility']['widget'][0]['#default_value'] = "3";
            }
          }

        }
      }

      // Do some alterations on this form.
      if ($this->operation == 'edit') {
        /** @var \Drupal\social_post\Entity\Post $post */
        $post = $this->entity;
        $form['#post_id'] = $post->id();

        // In edit mode we don't want people to actually change visibility
        // setting of the post.
        if ($current_value = $this->entity->get('field_visibility')->value) {
          // We set the default value.
          $form['field_visibility']['widget'][0]['#default_value'] = $current_value;
        }

        // Unset the other options, because we do not want to be able to change
        // it but we do want to use the button for informing the user.
        foreach ($form['field_visibility']['widget'][0]['#options'] as $key => $option) {
          if ($option['value'] != $form['field_visibility']['widget'][0]['#default_value']) {
            unset($form['field_visibility']['widget'][0]['#options'][$key]);
          }
        }

        // Set button to disabled in our template, users have no option anyway.
        $form['field_visibility']['widget'][0]['#edit_mode'] = TRUE;
      }
    }
    if ($this->entity->isNew()) {
      unset($form['status']);
    }
    else {
      $form['status']['#access'] = $this->currentUser->hasPermission('edit any post entities');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    // Init form modes.
    $this->setFormMode();

    $display = $this->getFormDisplay($form_state);

    if ($this->entity->isNew()) {
      if (isset($display) && ($display_id = $display->get('id'))) {
        if ($display_id === $this->postViewProfile) {
          $account_profile = \Drupal::routeMatch()->getParameter('user');
          $this->entity->get('field_recipient_user')->setValue($account_profile);
        }
        elseif ($display_id === $this->postViewGroup) {
          $group = \Drupal::routeMatch()->getParameter('group');
          $this->entity->get('field_recipient_group')->setValue($group);
        }
      }
    }

    $status = parent::save($form, $form_state);

    switch ($status) {
      case SAVED_NEW:
        drupal_set_message($this->t('Your post %label has been posted.', [
          '%label' => $this->entity->label(),
        ]));
        break;

      default:
        drupal_set_message($this->t('Your post %label has been saved.', [
          '%label' => $this->entity->label(),
        ]));
    }
  }

  /**
   * Function to set the current form modes.
   *
   * Retrieve the form display before it is overwritten in the parent.
   */
  protected function setFormMode() {
    if ($this->getBundleEntity() !== NULL) {
      $bundle = $this->getBundleEntity()->id();

      // Set as variables, since the bundle might be different.
      $this->postViewDefault = 'post.' . $bundle . '.default';
      $this->postViewProfile = 'post.' . $bundle . '.profile';
      $this->postViewGroup = 'post.' . $bundle . '.group';
    }
  }

}
