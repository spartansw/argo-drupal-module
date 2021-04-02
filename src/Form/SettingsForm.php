<?php

namespace Drupal\argo\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for Argo module.
 *
 * @package Drupal\argo\Form
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'argo.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'argo_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;
    $config = $this->config('argo.settings');

    $config_keys = $config->get('config.translatable') ?? [];
    $form['config']['translatable'] = [
      '#type' => 'textarea',
      '#rows' => 25,
      '#title' => $this->t('Configuration entity names to enable Argo translation for.'),
      '#description' => $this->t('One configuration name per line.<br />
Examples: <ul>
<li>views.settings</li>
<li>webform.webform.* (will include all config entities that start with <em>webform.webform</em>)</li>
</ul>'),
      '#default_value' => implode(PHP_EOL, $config_keys),
      '#size' => 60,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('argo.settings');
    $values = $form_state->getValues();

    $config_keys = preg_split("/[\r\n]+/", $values['config']['translatable']);
    $config_keys = array_filter($config_keys);
    $config_keys = array_values($config_keys);
    $config->set('config.translatable', $config_keys);
    $config->save();
  }

}
