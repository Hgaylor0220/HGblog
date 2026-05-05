<?php

declare(strict_types=1);

namespace Drupal\searchstax\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\searchstax\Exception\SearchStaxException;
use Drupal\searchstax\Service\ApiInterface;

/**
 * Provides methods for displaying a SearchStax login form.
 */
trait ApiLoginFormTrait {

  /**
   * The SearchStax API service.
   */
  protected ApiInterface $searchStaxApi;

  /**
   * Shows a login form for providing the SearchStax login credentials.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The form structure.
   */
  protected function showLogInForm(array $form, FormStateInterface $form_state): array {
    $form['login'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('SearchStax login'),
      '#description' => $this->t('Please provide your SearchStax login credentials to continue. These will not be stored on the server, they are only used to retrieve a temporary authentication token which will be valid for 24 hours.'),
    ];
    $form['login']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#description' => $this->t('The email address used when you log into the SearchStax server.'),
      '#required' => TRUE,
    ];
    $form['login']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Enter the password that accompanies your username.'),
      '#required' => TRUE,
    ];
    $form['login']['tfa_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('TFA Token'),
      '#description' => $this->t('In case your account has two-factor authentication enabled, enter a valid TFA token.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue'),
      '#validate' => [
        '::validateLoginForm',
      ],
      '#submit' => [
        '::submitLoginForm',
      ],
    ];

    return $form;
  }

  /**
   * Validation handler for the login form.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function validateLoginForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->searchStaxApi->login(
        $form_state->getValue('username'),
        $form_state->getValue('password'),
        $form_state->getValue('tfa_token') ?: NULL,
      );
    }
    catch (SearchStaxException $e) {
      $element = &$form;
      if (isset($element['login'])) {
        $element = &$element['login'];
      }
      $message = $this->t('The login failed: @message.', ['@message' => $e->getMessage()]);
      if ($e->getCode() === 400 && !empty($e->getResponse()['tfa_token_required'])) {
        $element = &$element['tfa_token'];
        $message = $this->t('Two-Factor Authentication is enabled for this account. Please provide a valid TFA token.');
      }
      $form_state->setError($element, $message);
    }
  }

  /**
   * Submit handler for the login form.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function submitLoginForm(array &$form, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Login was successful.'));
  }

}
