# Content Janitor

A robust, automated rules engine for Drupal 10/11 that cleans up stale content. 

Content Janitor allows site administrators to create dynamic rules that automatically unpublish nodes based on specific age thresholds and date fields. Instead of relying on rigid, hardcoded scripts, this module leverages Drupal's Configuration Entity system, allowing you to export your rules to `config/sync` and deploy them seamlessly across environments.

## Features

* **Config Entity Architecture:** Rules are saved as standard configuration, making them fully exportable and deployable.
* **Dynamic AJAX UI:** The "Add Rule" form uses AJAX to dynamically inspect your site's architecture. Select a Content Type, and the form will automatically populate a dropdown of only the Date/Datetime fields (including core `created` and `changed` fields) attached to that specific bundle.
* **Cron-Driven Batching:** Runs silently during Drupal's cron execution. Features a built-in `batch_limit` per rule to prevent memory timeouts on massive datasets.
* **Revision Tracking:** When a node is unpublishedThat is fantastic news! Building a custom Configuration Entity with dynamic AJAX form injection is a massive milestone. You just built an enterprise-grade Drupal module from scratch. 

Every great module needs a great `README.md` so the next developer (or future you) knows exactly how it works and how it was built. 

Here is a clean, professional README you can drop directly into the root of your `content_janitor` folder.

---

# Content Janitor

A robust, lightweight Rules Engine for automated content remediation and unpublishing in Drupal 10/11. 

Content Janitor allows site administrators to create dynamic rules that automatically unpublish stale content during routine Cron runs. Instead of relying on manual content audits, this module ensures your site's content remains fresh and relevant based on configurable date thresholds.

## 🚀 Features

* **Configuration Entities:** Built on modern Drupal 10/11 Config Entities. All rules are fully exportable to your site's `config/sync` directory for seamless environment deployments.
* **Dynamic AJAX UI:** The "Add Rule" form dynamically fetches and filters Date and DateTime fields based on the selected Content Type.
* **Core & Custom Field Support:** Intelligently handles both core integer timestamps (e.g., `created`, `changed`) and custom ISO-8601 string fields (e.g., `field_meeting_date`).
* **Cron-Powered Batching:** Processes unpublishing tasks quietly in the background during standard Cron runs.
* **Batch Limits:** Configurable limits per rule to prevent memory timeouts on massive datasets.
* **Revision Tracking:** Automatically creates a new revision with a log message when a node is unpublished by the Janitor.

## 📦 Installation

1. Place the `content_janitor` folder into your `modules/custom/` directory.
2. Enable the module via Drush:
   ```bash
   drush en content_janitor -y