<?php

// For local testing put username and password here, rename as
// env.php and add to .gitignore
// For production (e.g., Heroku) add these as environment variables.

putenv('BHL_API_KEY=');

// Optional.
//
// How long any one HTTP request may take, in seconds. Defaults to 20. Lookups we can do
// without (resolving an author to an item) use a shorter limit of their own, so this is
// really about the fetches and checks the tool depends on.
// putenv('BHL_WIKIDATA_TIMEOUT=20');

// Set to anything to record per-request timings, readable with get_profile().
// putenv('BHL_WIKIDATA_PROFILE=1');

?>
