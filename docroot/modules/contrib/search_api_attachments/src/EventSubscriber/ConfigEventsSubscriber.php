<?php

namespace Drupal\search_api_attachments\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\search_api_attachments\Cache\AttachmentsCacheFactory;
use Drupal\search_api_attachments\TextExtractorPluginBase;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to config crud events.
 */
class ConfigEventsSubscriber implements EventSubscriberInterface {

  public function __construct(protected AttachmentsCacheFactory $attachmentsCacheFactory) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'configSave',
    ];
  }

  /**
   * React to a config object being saved.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   Config crud event.
   */
  public function configSave(ConfigCrudEvent $event) {
    $config = $event->getConfig();
    // Clear the old cache.
    if ($config->getName() == TextExtractorPluginBase::CONFIGNAME && $event->isChanged('cache_backend')) {
      /** @var \Drupal\search_api_attachments\Cache\AttachmentsCacheInterface $attachments_cache */
      $service_name = $config->getOriginal('cache_backend');
      if (!empty($service_name)) {
        try {
          $this->attachmentsCacheFactory->getById($service_name)->clearAll();
        }
        catch (\Exception) {
          // Ignore errors in case the old cache backend is not valid.
        }
      }
    }
  }

}
