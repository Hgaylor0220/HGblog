<?php

namespace Drupal\content_janitor\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the Content Janitor Rule entity.
 *
 * @ConfigEntityType(
 *   id = "content_janitor_rule",
 *   label = @Translation("Content Janitor Rule"),
 *   handlers = {
 *     "list_builder" = "Drupal\content_janitor\ContentJanitorRuleListBuilder",
 *     "form" = {
 *       "add" = "Drupal\content_janitor\Form\ContentJanitorRuleForm",
 *       "edit" = "Drupal\content_janitor\Form\ContentJanitorRuleForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     }
 *   },
 *   config_prefix = "content_janitor_rule",
 *   admin_permission = "administer site configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "target_content_type",
 *     "target_date_field",
 *     "age_threshold",
 *     "batch_limit"
 *   },
 *   links = {
 *     "collection" = "/admin/config/system/content-janitor",
 *     "add-form" = "/admin/config/system/content-janitor/add",
 *     "edit-form" = "/admin/config/system/content-janitor/{content_janitor_rule}",
 *     "delete-form" = "/admin/config/system/content-janitor/{content_janitor_rule}/delete"
 *   }
 * )
 */
class ContentJanitorRule extends ConfigEntityBase {
  public $id;
  public $label;
  public $target_content_type;
  public $target_date_field;
  public $age_threshold;
  public $batch_limit;
}