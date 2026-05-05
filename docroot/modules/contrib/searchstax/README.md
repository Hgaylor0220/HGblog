# SearchStax

The SearchStax Drupal Module lets users set up and configure the
[SearchStax Site Search product] (subscription required) on Drupal.

For a full description of the module, visit the [project page].

[SearchStax Site Search product]: https://www.searchstax.com/
[project page]: https://www.drupal.org/project/searchstax

## Requirements

This module requires the [Search API] module and a subscription to [SearchStax].

[Search API]: https://www.drupal.org/project/search_api
[SearchStax]: https://www.searchstax.com/


## Recommended modules

- [Search API Solr]: To use a SearchStax Solr server with the Search API module,
  the `search_api_solr` module is required. Without that module, only tracking
  functionality is provided by this module.
- [Search API Autocomplete]: To use the [auto-suggest] feature of SearchStax,
  the `search_api_autocomplete` module is recommended. After installing that
  module, visit the SearchStax configuration page and fill out the “Auto-suggest
  endpoint” field. You can find this value on the
  [“All APIs > Search & Indexing” page][search-and-indexing-page].

[Search API Solr]: https://www.drupal.org/project/search_api_solr
[Search API Autocomplete]: https://www.drupal.org/project/search_api_autocomplete
[auto-suggest]: https://www.searchstax.com/docs/searchstudio/searchstax-studio-auto-suggest/
[search-and-indexing-page]: https://www.searchstax.com/docs/searchstudio/searchstax-studio-search-api-tab/


## Installation

Install the module along with its dependencies. Make sure to use Composer for
installing the [Search API Solr] module so its dependencies will also be 
installed.


## Configuration

1. Create a new Search API server with a Solr backend. (Refer to the
   [Search API] documentation for details.) Use the “SearchStax Cloud with Token
   Auth” connector plugin if your account supports token authentication and “Solr
   Cloud with Basic Auth” otherwise.
2. Go to the module’s configuration page (`/admin/config/search/searchstax`) and
   enter the necessary information from your SearchStax account to enable
   auto-suggest, tracking or other additional functionality.
