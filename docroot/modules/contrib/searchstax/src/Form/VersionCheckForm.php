<?php

declare(strict_types=1);

namespace Drupal\searchstax\Form;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\searchstax\Exception\NotLoggedInException;
use Drupal\searchstax\Exception\SearchStaxException;
use Drupal\searchstax\Service\SearchStaxServiceInterface;
use Drupal\searchstax\Service\VersionCheckInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for checking compatibility of SearchStax apps with Drupal.
 */
class VersionCheckForm extends FormBase implements TrustedCallbackInterface {

  use ApiLoginFormTrait;

  /**
   * The version check service.
   */
  protected VersionCheckInterface $versionCheck;

  /**
   * The utility service.
   *
   * @var \Drupal\searchstax\Service\SearchStaxServiceInterface
   */
  protected SearchStaxServiceInterface $utility;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $form = parent::create($container);

    $form->setStringTranslation($container->get('string_translation'));
    $form->setMessenger($container->get('messenger'));
    $form->searchStaxApi = $container->get('searchstax.api');
    $form->versionCheck = $container->get('searchstax.version_check');
    $form->utility = $container->get('searchstax.utility');
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->dateFormatter = $container->get('date.formatter');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRenderForm'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'searchstax_version_check';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // This might be a rebuild because a SearchStax login is needed.
    $rebuild_info = $form_state->getRebuildInfo();
    if ($form_state->isRebuilding() && !empty($rebuild_info['login_needed'])) {
      $this->messenger()->addStatus($this->t('You need to log into your SearchStax account to continue.'));
      if (!empty($rebuild_info['operation_args'])) {
        $form_state->set('operation_args', $rebuild_info['operation_args']);
      }
      return $this->showLogInForm($form, $form_state);
    }

    try {
      /** @var \Drupal\search_api\ServerInterface[] $servers */
      $servers = $this->entityTypeManager->getStorage('search_api_server')
        ->loadMultiple();
    }
    catch (PluginException $e) {
      $this->messenger()->addError($this->t('Error retrieving search servers: @message.', [
        '@message' => $e->getMessage(),
      ]));
      return $form;
    }

    $form['version_info'] = [
      '#markup' => $this->t('Checking compatibility with Drupal major version %version.', [
        '%version' => (string) $this->versionCheck->getDrupalMajorVersion(),
      ]),
    ];

    $num_total = $num_not_checked = $num_incompatible = 0;
    $rows = [];
    foreach ($servers as $server_id => $server) {
      if (!$this->utility->isSearchStaxSolrServer($server)) {
        continue;
      }
      try {
        $server_label = $server->toLink();
      }
      catch (EntityMalformedException $ignored) {
        $server_label = $server->label() ?: $server->id();
      }
      ++$num_total;
      $data = NULL;
      if ($this->versionCheck->hasCompatibilityDataStored($server)) {
        try {
          $data = $this->versionCheck->checkCompatibility($server);
        }
        catch (SearchStaxException $ignored) {
          // Ignore at this point. Should actually never happen because we
          // should have data stored.
        }
      }
      $form['buttons'][] = [
        '#type' => 'submit',
        '#value' => $data ? $this->t('Re-check') : $this->t('Check'),
        '#name' => "check-$server_id",
        '#row_no' => count($rows),
      ];
      if (!$data) {
        ++$num_not_checked;
        $status = $this->t('Not checked');
        $last_checked = $this->t('Never');
      }
      else {
        $last_checked = $this->dateFormatter->format($data->getCheckedAt(), 'short');
        if ($data->isCompatible()) {
          $status = $this->t('Compatible');
        }
        else {
          ++$num_incompatible;
          $status = $data->getMessage();
          $form['buttons'][] = [
            '#type' => 'submit',
            '#value' => $this->t('Upgrade'),
            '#name' => "upgrade-$server_id",
            '#row_no' => count($rows),
          ];
        }
      }
      $rows[] = [
        'server' => $server_label,
        'status' => $status,
        'last_checked' => $last_checked,
        'operations' => [],
      ];
    }
    $form['table'] = [
      '#theme' => 'table',
      '#header' => [
        $this->t('Server'),
        $this->t('Status'),
        $this->t('Last checked'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No SearchStax servers found.'),
    ];

    // Unfortunately, the Form API cannot handle buttons nested inside a table.
    // We have to first put them on the top level of the form and then only move
    // them to their correct places in a pre-render callback.
    if (!empty($form['buttons'])) {
      $form['#pre_render'] = [
        [$this, 'preRenderForm'],
      ];
    }

    // Provide a summary at the top of the page unless the form is being
    // submitted.
    if (!$form_state->getUserInput()) {
      if ($num_incompatible) {
        if ($num_total > 1) {
          $message = $this->formatPlural(
            $num_incompatible,
            'The configuration files of 1 SearchStax app are incompatible with the currently used version of Drupal. Please click the “Upgrade” button next to it.',
            'The configuration files of @count SearchStax apps are incompatible with the currently used version of Drupal. Please click the “Upgrade” buttons next to each.',
          );
        }
        else {
          $message = $this->t('The configuration files of your SearchStax app are incompatible with the currently used version of Drupal. Please click the “Upgrade” button.');
        }
        $this->messenger()->addError($message);
      }
      elseif ($num_not_checked) {
        if ($num_total > 1) {
          $message = $this->formatPlural(
            $num_not_checked,
            '1 SearchStax app has not been checked against the current major version of Drupal. Please click the “Check” button next to it.',
            '@count SearchStax apps have not been checked against the current major version of Drupal. Please click the “Check” buttons next to each.',
          );
        }
        else {
          $message = $this->t('Your SearchStax app has not been checked against the current major version of Drupal. Please click the “Check” button.');
        }
        $this->messenger()->addWarning($message);
      }
      elseif ($num_total) {
        $this->messenger()->addStatus($this->formatPlural(
          $num_total,
          'The configuration files of your SearchStax app are compatible with the currently used version of Drupal.',
          'The configuration files of all @count SearchStax apps are compatible with the currently used version of Drupal.',
        ));
      }
    }

    return $form;
  }

  /**
   * Prerender callback for the form.
   *
   * Moves the buttons into the table.
   *
   * @param array $form
   *   The form.
   *
   * @return array
   *   The processed form.
   */
  public function preRenderForm(array $form): array {
    foreach (Element::children($form['buttons']) as $key) {
      $button = $form['buttons'][$key];
      // Move the button into the appropriate table row.
      $form['table']['#rows'][$button['#row_no']]['operations']['data'][] = $button;
    }
    unset($form['buttons']);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $button = $form_state->getTriggeringElement();
    $button_name = $button['#name'] ?? '';
    [$op, $server_id] = explode('-', $button_name) + [1 => NULL];
    if (!in_array($op, ['check', 'upgrade']) || !$server_id) {
      // Something strange is going on.
      $this->messenger()->addError($this->t('Form processing error: invalid button name "@operation".', [
        '@operation' => $button_name,
      ]));
      return;
    }
    $this->executeOperation($op, $server_id, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitLoginForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('operation_args')) {
      $args = $form_state->get('operation_args');
      $args[] = $form_state;
      $this->executeOperation(...$args);
    }
  }

  /**
   * Executes the given operation during form submission.
   *
   * @param string $op
   *   The operation: "check" or "upgrade".
   * @param string $server_id
   *   The search server ID.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function executeOperation(
    string $op,
    string $server_id,
    FormStateInterface $form_state
  ): void {
    try {
      /** @var \Drupal\search_api\ServerInterface|null $server */
      $server = $this->entityTypeManager->getStorage('search_api_server')->load($server_id);
      if (!$server) {
        throw new SearchStaxException("Could not load server with ID \"$server_id\".");
      }
      if ($op === 'check') {
        $this->versionCheck->checkCompatibility($server, TRUE);
        $this->messenger()->addStatus($this->t('Successfully checked compatibility status.'));
      }
      else {
        $this->versionCheck->upgradeApp($server);
        $this->messenger()->addStatus($this->t('Successfully upgraded the SearchStax app.'));
      }
    }
    catch (NotLoggedInException $e) {
      $form_state->setRebuild();
      $form_state->setRebuildInfo([
        'login_needed' => TRUE,
        'operation_args' => [$op, $server_id],
      ]);
    }
    catch (SearchStaxException | PluginException $e) {
      $this->messenger()->addError($this->t('An error occurred: @message.', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

}
