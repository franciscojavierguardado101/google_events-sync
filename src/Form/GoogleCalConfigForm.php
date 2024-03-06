<?php

namespace Drupal\google_events\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for your module settings.
 */
class GoogleCalConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['google_events.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_events_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('google_events.settings');
    $form['google_calendar_credentials_json'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Google Calendar Credentials JSON'),
        '#upload_location' => 'private://google_calendar_json',
        '#description' => $this->t('Upload your Google Calendar JSON file here.'),
        '#required' => TRUE,
        '#default_value' => $config->get('google_calendar_credentials_json'),
        '#upload_validators' => [
          'file_validate_extensions' => ['json'],
        ],
      ];
      

    $form['google_calendar_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google Calendar ID'),
      '#default_value' => $config->get('google_calendar_id'),
      '#description' => $this->t('Enter your Google Calendar ID.'),
      '#required' => TRUE,
    ];

    $form['google_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google API key'),
      '#default_value' => $config->get('google_api_key'),
      '#description' => $this->t('Enter your API key.'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('google_events.settings')
      ->set('google_calendar_credentials_json', $form_state->getValue('google_calendar_credentials_json'))
      ->set('google_calendar_id', $form_state->getValue('google_calendar_id'))
      ->set('google_api_key', $form_state->getValue('google_api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
