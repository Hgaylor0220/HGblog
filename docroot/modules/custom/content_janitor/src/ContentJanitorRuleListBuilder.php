<?php

namespace Drupal\content_janitor;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

class ContentJanitorRuleListBuilder extends ConfigEntityListBuilder {

  public function buildHeader() {
    $header['label'] = $this->t('Rule Name');
    $header['content_type'] = $this->t('Content Type');
    $header['threshold'] = $this->t('Threshold');
    return $header + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity) {
    $row['label'] = $entity->label();
    $row['content_type'] = $entity->target_content_type;
    $row['threshold'] = $entity->age_threshold;
    return $row + parent::buildRow($entity);
  }

}