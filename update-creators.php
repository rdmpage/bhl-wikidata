<?php

// Rebuild creators.json, the BHL creator id -> Wikidata item cache.
//
// Wikidata holds fewer than 60,000 P4081 statements, so rather than discovering them one
// batch at a time while somebody waits for a page to load, we fetch the lot in a single
// query and ship the result. That leaves the live lookup only for creator ids added to
// Wikidata since the last run.
//
// Run from the command line:
//
//   php update-creators.php
//
// Re-run it whenever you want to pick up newly linked authors, and commit the result.

if (php_sapi_name() != 'cli')
{
	die("This script is meant to be run from the command line\n");
}

if (file_exists(dirname(__FILE__) . '/env.php'))
{
	include dirname(__FILE__) . '/env.php';
}

require_once(dirname(__FILE__) . '/wikidata.php');

$filename = dirname(__FILE__) . '/creators.json';

//----------------------------------------------------------------------------------------
// Keep whatever we already know about creator ids that aren't in Wikidata, so a rebuild
// doesn't throw away the record of what we've checked.
$existing_misses = array();

$cache = bhl_creator_cache_load($filename);

if (isset($cache['misses']) && is_array($cache['misses']))
{
	$existing_misses = $cache['misses'];
}

echo "Fetching every BHL creator id in Wikidata...\n";

$sparql = 'SELECT ?creator ?author WHERE { ?author wdt:P4081 ?creator }';

$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);

// This is a big result set and nobody is waiting on a web request, so allow plenty of time
$csv = get($url, '', 'text/csv', 300);

if ($csv === false || trim($csv) == '')
{
	die("Failed to fetch the creator ids, nothing written\n");
}

//----------------------------------------------------------------------------------------
// Parse. A creator id claimed by more than one item is ambiguous, and the lookup has always
// treated those as no match, so drop them rather than pick one arbitrarily.
$counts = array();
$items = array();

$lines = preg_split('/\r\n|\r|\n/', $csv);

$header = true;

foreach ($lines as $line)
{
	if ($header)
	{
		$header = false;
		continue;
	}

	if (trim($line) == '')
	{
		continue;
	}

	$fields = str_getcsv($line);

	if (count($fields) < 2)
	{
		continue;
	}

	$creator = trim($fields[0]);
	$item = preg_replace('/^https?:\/\/www\.wikidata\.org\/entity\//', '', trim($fields[1]));

	if ($creator == '' || !preg_match('/^Q\d+$/', $item))
	{
		continue;
	}

	$counts[$creator] = isset($counts[$creator]) ? $counts[$creator] + 1 : 1;
	$items[$creator] = $item;
}

$hits = array();
$ambiguous = 0;

foreach ($items as $creator => $item)
{
	if ($counts[$creator] == 1)
	{
		$hits[$creator] = $item;
	}
	else
	{
		$ambiguous++;
	}
}

if (count($hits) == 0)
{
	die("No usable creator ids parsed, nothing written\n");
}

//----------------------------------------------------------------------------------------
// A creator id we now have an item for is no longer a miss
foreach ($hits as $creator => $item)
{
	unset($existing_misses[$creator]);
}

$cache = array(
	'updated'	=> date('Y-m-d'),
	'hits'		=> $hits,
	'misses'	=> $existing_misses
);

$written = file_put_contents(
	$filename,
	json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
	LOCK_EX);

if ($written === false)
{
	die("Could not write " . $filename . "\n");
}

printf("Wrote %s\n", $filename);
printf("  %d creator ids with an item\n", count($hits));
printf("  %d ambiguous (claimed by more than one item, skipped)\n", $ambiguous);
printf("  %d known misses carried over\n", count($existing_misses));
printf("  %.1f MB\n", $written / 1024 / 1024);

?>
