<?php

namespace Drupal\searchstax\Form;

use Drupal\Core\Config\ConfigBase;
use Drupal\Core\Render\Element;

/**
 * Provides backwards-compatibility helpers for config forms.
 *
 * @internal
 */
trait ConfigFormBcTrait {

  /**
   * Determines whether this class needs to explicitly handle config changes.
   *
   * Earlier versions of Drupal didn't come with the automatic config handling
   * via the "#config_target" keys in ConfigFormBase, so we need to set default
   * values and save the new config values ourselves.
   *
   * @return bool
   *   TRUE if this form class needs to explicitly handle config changes, FALSE
   *   if this is a Drupal version that includes the automatic handling in
   *   ConfigFormBase.
   *
   * @see \Drupal\Core\Form\ConfigFormBase::copyFormValuesToConfig()
   */
  protected static function manualConfigHandlingNeeded(): bool {
    return version_compare(\Drupal::VERSION, '10.2', '<');
  }

  /**
   * Sets the default values for the given form element's children.
   *
   * @param array $element
   *   The form element, passed by reference.
   * @param \Drupal\Core\Config\ConfigBase $config
   *   The module config.
   */
  protected static function setConfigDefaultValues(array &$element, ConfigBase $config): void {
    foreach (Element::children($element) as $key) {
      $child = &$element[$key];
      $type = $child['#type'] ?? 'details';
      if (in_array($type, ['fieldset', 'details'], TRUE)) {
        static::setConfigDefaultValues($child, $config);
      }
      if (empty($child['#config_target'])) {
        continue;
      }
      [$config_key, $property] = explode(':', $child['#config_target']);
      if ($config_key !== 'searchstax.settings') {
        continue;
      }
      $value = $config->get($property);
      if ($value !== NULL) {
        $child['#default_value'] = $value;
      }
    }
  }

}
