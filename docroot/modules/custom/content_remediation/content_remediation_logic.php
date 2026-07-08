<?php
// START AI-GENERATED CODE

/**
 * @file
 * Contains content_remediation.module.
 * Automated tasks for maintaining content hygiene across the Site Factory.
 */

use Drupal\node\Entity\Node;

/**
 * Implements hook_cron().
 */
function content_remediation_cron() {
  // 1. Enterprise Throttling: Only run this heavy query once every 24 hours.
  $last_run = \Drupal::state()->get('content_remediation.last_daily_run', 0);
  $request_time = \Drupal::time()->getRequestTime();
  
  if ($request_time - $last_run < 86400) {
    return; // 24 hours haven't passed yet, exit early.
  }

  // Define the target field and the exact date 2 years ago
  // IMPORTANT: Change 'field_meeting_date' to your actual machine name
  $field_name = 'field_meeting_date'; 
  $two_years_ago = date('Y-m-d\TH:i:s', strtotime('-2 years'));
  
  // 2. Batch Limit: Process maximum 50 nodes per run to prevent memory exhaustion
  $limit = 50;

  try {
    // 3. Query for published pages where the meeting date is older than 2 years
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'page')
      ->condition('status', 1) // 1 = Published
      ->condition($field_name, $two_years_ago, '<')
      ->range(0, $limit)
      ->accessCheck(FALSE); // Required parameter for Drupal 10 compatibility
    
    $nids = $query->execute();

    if (empty($nids)) {
      // If no nodes are found, update the timestamp so we don't check again until tomorrow.
      \Drupal::state()->set('content_remediation.last_daily_run', $request_time);
      return;
    }

    // 4. Load and safely unpublish
    $nodes = Node::loadMultiple($nids);
    $count = 0;
    
    foreach ($nodes as $node) {
      $node->setUnpublished();
      
      // Create a new revision so content editors see an audit trail
      $node->setNewRevision(TRUE);
      $node->revision_log = 'Automated bulk unpublish by Cron: Meeting date is older than 2 years.';
      $node->setRevisionUserId(1); // Attributed to the Admin user
      
      $node->save();
      $count++;
    }
    
    // 5. Log the action to Drupal's Watchdog
    if ($count > 0) {
      \Drupal::logger('content_remediation')->notice(
        'Automated Remediation: Successfully unpublished @count stale page nodes.', 
        ['@count' => $count]
      );
    }

    // Update the State API so this waits another 24 hours before running again.
    \Drupal::state()->set('content_remediation.last_daily_run', $request_time);

  } catch (\Exception $e) {
    // Gracefully catch and log any database or field missing errors
    \Drupal::logger('content_remediation')->error(
      'Content remediation cron failed: @message', 
      ['@message' => $e->getMessage()]
    );
  }
}
// END AI-GENERATED CODE