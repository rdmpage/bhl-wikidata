<?php

// Environment----------------------------------------------------------------------------
// In development this is a PHP file that is in .gitignore, when deployed these parameters
// will be set on the server
if (file_exists(dirname(__FILE__) . '/env.php'))
{
	include 'env.php';
}

require_once(dirname(__FILE__) . '/shared.php');
require_once(dirname(__FILE__) . '/wikidata.php');

//----------------------------------------------------------------------------------------
// Work out what kind of identifier the user gave us.
//
// DOIs are recognised by their shape, everything else is namespaced, e.g. "biostor:192990".
// Returns array('type' => ..., 'value' => ...) with type 'unknown' if we can't tell.
function parse_identifier($id)
{
	$id = trim($id);

	$parsed = array(
		'type' 	=> 'unknown',
		'value'	=> $id
	);

	if ($id == '')
	{
		return $parsed;
	}

	// Namespaced identifier, e.g. biostor:192990
	if (preg_match('/^(?<namespace>[a-z]+)\s*:\s*(?<value>\d+)$/i', $id, $m))
	{
		switch (strtolower($m['namespace']))
		{
			case 'biostor':
				$parsed['type'] = 'biostor';
				$parsed['value'] = $m['value'];
				return $parsed;

			case 'bhlpart':
				$parsed['type'] = 'bhlpart';
				$parsed['value'] = $m['value'];
				return $parsed;

			default:
				return $parsed;
		}
	}

	if (preg_match('/^10\.[0-9]{4,}(?:\.[0-9]+)*(?:\/|%2F)(?:(?![\"&\'])\S)+/', $id))
	{
		$parsed['type'] = 'doi';
		return $parsed;
	}

	return $parsed;
}

//----------------------------------------------------------------------------------------
// Take a work, decide whether Wikidata already has it, and return what we should do about
// it. Returns array with:
//   status	 'create'  we have Quickstatements to make a new item
//			 'update'  the item exists but is missing identifiers we hold
//			 'exists'  the item exists and has everything we hold
//			 'bad'	   we couldn't make anything of this identifier
//   quickstatements   the statements to run (for 'create' and 'update')
//   item			   the Wikidata item (for 'update' and 'exists')
function result_from_work($work, $languages_to_detect, $source, $force = false)
{
	$result = array(
		'status' 			=> 'bad',
		'quickstatements'	=> '',
		'item'				=> ''
	);

	if (!$work)
	{
		return $result;
	}

	// $force skips the "already exists" test so we can reproduce the full workload for a
	// record that has since been added by hand (see issue #21)
	$check = $force ? false : true;

	$q = csljson_to_wikidata($work, $check, false, $languages_to_detect, $source);

	if (!$q)
	{
		return $result;
	}

	if (preg_match('/^CREATE/', $q))
	{
		$result['status'] = 'create';
		$result['quickstatements'] = $q;

		return $result;
	}

	// csljson_to_wikidata returned an item, so Wikidata has this already. See whether we
	// hold any identifiers it lacks.
	$result['item'] = $q;

	$missing = wikidata_missing_identifier_statements($q, $work);

	if ($missing != '')
	{
		$result['status'] = 'update';
		$result['quickstatements'] = $missing;
	}
	else
	{
		$result['status'] = 'exists';
	}

	return $result;
}

//----------------------------------------------------------------------------------------
// Which languages should we try to detect for this work?
function languages_for_agency($agency)
{
	$languages_to_detect = array('en', 'fr', 'de', 'pt', 'es', 'ja', 'zh', 'ru', 'ar', 'pa', 'hi');

	if ($agency == 'JaLC')
	{
		// hack
		$languages_to_detect = array('ja', 'en', 'de', 'fr');
	}

	return $languages_to_detect;
}

//----------------------------------------------------------------------------------------
// Reference for the statements we generate from a DOI
function source_for_doi($doi, $agency)
{
	$source = array();

	switch ($agency)
	{
		case 'Crossref':
			$source[] = 'S248';
			$source[] = 'Q5188229'; // CrossRef
			$source[] = 'S854';
			$source[] = '"' . 'https://api.crossref.org/v1/works/' . $doi . '"';
			break;

		case 'DataCite':
			$source[] = 'S248';
			$source[] = 'Q821542'; // DataCite
			$source[] = 'S854';
			$source[] = '"' . 'https://doi.org/' . $doi . '"';
			break;

		case 'JaLC':
			$source[] = 'S248';
			$source[] = 'Q100319347'; // JaLC
			$source[] = 'S854';
			$source[] = '"' . 'https://doi.org/' . $doi . '"';
			break;

		default:
			break;
	}

	return $source;
}

//----------------------------------------------------------------------------------------
// Copy the identifiers and author ids BHL holds for a part onto a work fetched from a DOI
// registration agency.
function add_part_to_work($work, $part)
{
	if (!$work || !$part)
	{
		return;
	}

	// authors
	if (isset($work->message->author) && isset($part->Authors))
	{
		$n1 = count($work->message->author);
		$n2 = count($part->Authors);

		if ($n1 == $n2)
		{
			for ($i = 0; $i < $n1; $i++)
			{
				if (isset($part->Authors[$i]->CreatorID))
				{
					$work->message->author[$i]->BHL = $part->Authors[$i]->CreatorID;
				}
			}
		}
	}

	// identifiers
	if (isset($part->PartID))
	{
		$work->message->BHLPART = $part->PartID;
	}

	if (isset($part->StartPageID))
	{
		$work->message->BHL = $part->StartPageID;
	}

	if (isset($part->ItemID))
	{
		$work->message->ItemID = $part->ItemID;
	}

	if (isset($part->Identifiers))
	{
		foreach ($part->Identifiers as $identifier)
		{
			switch ($identifier->IdentifierName)
			{
				case 'BioStor':
					$work->message->BIOSTOR = $identifier->IdentifierValue;
					break;

				default:
					break;
			}
		}
	}

	// BHL knows which Wikidata item the journal is, which saves us a lookup
	$journal = bhl_journal_from_part($part);

	if ($journal['JOURNAL'] != '' && !isset($work->message->JOURNAL))
	{
		$work->message->JOURNAL = $journal['JOURNAL'];
	}

	if (count($journal['ISSN']) > 0 && !isset($work->message->ISSN))
	{
		$work->message->ISSN = $journal['ISSN'];
	}
}

//----------------------------------------------------------------------------------------
// $force = true processes the DOI as if it were not yet in Wikidata. Used for debugging
// timeouts on DOIs that have since been added by hand (see issue #21).
//
// $part may be supplied by the caller when we already have the BHL record, which saves
// looking it up again. $bhl_work is the record built from that part: when we arrived here
// from a BHL identifier it tells us which identifiers we hold, so that a work already in
// Wikidata can still be given the ones it lacks.
function add_from_doi($doi, $force = false, $part = null, $bhl_work = null)
{
	$doi = strtolower($doi);

	// Two different inputs can resolve to the same work (a BioStor id and the BHL part id
	// for the same article, say), so every result carries a key identifying the work it
	// resolved to and the caller drops repeats.
	$key = 'doi:' . $doi;

	if (!$force)
	{
		// Short circuit: if Wikidata already has the DOI we don't need to build the whole
		// record, we only need to know whether we hold identifiers it is missing
		$item = wikidata_item_from_doi($doi);
		
		if ($item != '')
		{
			$missing = $bhl_work ? wikidata_missing_identifier_statements($item, $bhl_work) : '';
			
			return array(
				'status'			=> $missing == '' ? 'exists' : 'update',
				'quickstatements'	=> $missing,
				'item'				=> $item,
				'key'				=> $key
			);
		}
	}

	$work = get_work($doi);

	if (!$work)
	{
		return array('status' => 'bad', 'quickstatements' => '', 'item' => '', 'key' => $key);
	}

	if (!$part)
	{
		// Is this a BHL part?
		if (preg_match('/10\.5962\/(bhl\.part|p)\.(?<part>\d+)/i', $doi, $m))
		{
			// If this is a BHL DOI for a part we attempt to match authors to BHL ids
			$part = get_part_from_bhl_part($m['part']);
		}
		else
		{
			// DOI might be an external DOI in BHL
			$part = get_part_from_doi($doi);
		}
	}

	add_part_to_work($work, $part);

	$agency = doi_to_agency($doi);

	$result = result_from_work(
		$work,
		languages_for_agency($agency),
		source_for_doi($doi, $agency),
		$force
		);

	$result['key'] = $key;

	return $result;
}

//----------------------------------------------------------------------------------------
// Generate from a BHL part record, i.e. what a BioStor id or BHL part id resolved to.
//
// If BHL knows a DOI for the part we go the DOI route, which gives us richer metadata from
// the registration agency, and pass the part we already have so it isn't fetched twice.
// Otherwise we build the record from BHL's own metadata.
function add_from_bhl_part($part, $force = false)
{
	if (!$part)
	{
		return array('status' => 'bad', 'quickstatements' => '', 'item' => '', 'key' => '');
	}

	$work = bhl_part_to_csljson($part);

	if (!$work)
	{
		return array('status' => 'bad', 'quickstatements' => '', 'item' => '', 'key' => '');
	}

	// Does BHL know a DOI for this? If so the registration agency has better metadata
	if (isset($work->message->DOI))
	{
		return add_from_doi($work->message->DOI, $force, $part, $work);
	}

	// No DOI, so BHL is our source
	$source = array(
		'S248',
		'Q172266', // Biodiversity Heritage Library
		'S854',
		'"https://www.biodiversitylibrary.org/part/' . $part->PartID . '"'
	);

	$result = result_from_work(
		$work,
		array('en', 'fr', 'de', 'pt', 'es', 'ja', 'zh', 'ru', 'ar', 'pa', 'hi'),
		$source,
		$force
		);

	$result['key'] = 'part:' . $part->PartID;

	return $result;
}

//----------------------------------------------------------------------------------------
// Work out which work an identifier refers to, without doing any of the expensive
// generation. Two identifiers can name the same work, and building the statements twice
// would propose two items for it, so we settle identity first and generate afterwards.
//
// Returns array('key' => ..., 'part' => ..., 'doi' => ...) with an empty key if we can't
// resolve the identifier at all.
function resolve_identifier($parsed)
{
	$resolved = array(
		'key'	=> '',
		'part'	=> null,
		'doi'	=> ''
	);

	switch ($parsed['type'])
	{
		case 'doi':
			$resolved['doi'] = strtolower($parsed['value']);
			$resolved['key'] = 'doi:' . $resolved['doi'];
			break;

		case 'biostor':
			$resolved['part'] = get_part_from_biostor($parsed['value']);
			break;

		case 'bhlpart':
			$resolved['part'] = get_part_from_bhl_part($parsed['value']);
			break;

		default:
			break;
	}

	if ($resolved['part'])
	{
		// A part with a DOI is the same work as that DOI
		$doi = bhl_part_doi($resolved['part']);

		if ($doi != '')
		{
			$resolved['doi'] = $doi;
			$resolved['key'] = 'doi:' . $doi;
		}
		else if (isset($resolved['part']->PartID))
		{
			$resolved['key'] = 'part:' . $resolved['part']->PartID;
		}
	}

	return $resolved;
}

//----------------------------------------------------------------------------------------

if (isset($_GET['ids']) && trim($_GET['ids']) != "")
{
	// process
	$results = array();

	$ids = explode("\n", trim($_GET['ids']));

	// Debugging: &force=1 ignores the "already in Wikidata" test so we can reproduce
	// the full workload for a DOI that has since been created by hand (issue #21).
	$force = isset($_GET['force']) && $_GET['force'] != '';

	// Settle which work each identifier refers to before generating anything: resolving is
	// cheap, generating is not, and two identifiers can name the same work.
	$seen = array();
	$duplicates = array();
	$to_process = array();

	foreach ($ids as $id)
	{
		$id = trim($id);

		if ($id == '')
		{
			continue;
		}

		if (isset($to_process[$id]) || isset($duplicates[$id]) || isset($results[$id]))
		{
			// same identifier entered twice
			continue;
		}

		$parsed = parse_identifier($id);

		if ($parsed['type'] == 'unknown')
		{
			$results[$id] = array('status' => 'bad', 'quickstatements' => '', 'item' => '', 'key' => '');
			continue;
		}

		$resolved = resolve_identifier($parsed);

		if ($resolved['key'] == '')
		{
			// we recognised the identifier but couldn't resolve it to anything
			$results[$id] = array('status' => 'bad', 'quickstatements' => '', 'item' => '', 'key' => '');
			continue;
		}

		if (isset($seen[$resolved['key']]))
		{
			$duplicates[$id] = $seen[$resolved['key']];
			continue;
		}

		$seen[$resolved['key']] = $id;
		$to_process[$id] = $resolved;
	}

	foreach ($to_process as $id => $resolved)
	{
		if ($resolved['part'])
		{
			$results[$id] = add_from_bhl_part($resolved['part'], $force);
		}
		else
		{
			$results[$id] = add_from_doi($resolved['doi'], $force);
		}
	}

	// Sort the results into what we can do with them
	$to_create = array();
	$to_update = array();
	$have_already = array();
	$bad_identifier = array();

	foreach ($results as $id => $result)
	{
		switch ($result['status'])
		{
			case 'create':
				$to_create[$id] = $result['quickstatements'];
				break;

			case 'update':
				$to_update[$id] = $result['quickstatements'];
				break;

			case 'exists':
				$have_already[$id] = $result['item'];
				break;

			default:
				$bad_identifier[] = $id;
				break;
		}
	}

?>

<html>
<head>
	<meta charset="utf-8" />
	<title>BHL Wikidata</title>
	<style>
		body {
			font-family:sans-serif;
			padding:40px;
			color:#424242;
		}

	button {
		font-size:1em;
		background-color:blue;
		color:white;
		border:1px solid white;
		padding:1em;
		border-radius:4px;
	}

	a {
		text-decoration:none;
		color:rgb(28,27,168);
	}

	table {
		border-collapse:collapse;
		margin-bottom:2em;
	}

	th, td {
		text-align:left;
		padding:0.4em 1em 0.4em 0;
		border-bottom:1px solid #e0e0e0;
	}

	</style>
</head>
<body>
<h1>
	<a href=".">[Home]</a>
</h1>

<?php

// Only show a Quickstatements box when there is actually something to put in it
if (count($to_create) > 0)
{
?>
<h2><?php echo count($to_create); ?> new item(s)</h2>

<p>You can create these in QuickStatements:</p>

<form action='https://tools.wmflabs.org/quickstatements/api.php' method='post' target='_blank'>
<input type='hidden' name='action' value='import' />
<input type='hidden' name='format' value='v1' />
<input type='hidden' name='temporary' value='1' />
<input type='hidden' name='openpage' value='1' />
<textarea style="padding:1em;font-size:1em;box-sizing: border-box;width:100%;" name="data" rows="20" ><?php

foreach ($to_create as $id => $quickstatements)
{
	echo $quickstatements . "\n";
}

?></textarea>
    <br />
    <button type="submit">Open in Quickstatements</button>
</form>
<?php
}

if (count($to_update) > 0)
{
?>
<h2><?php echo count($to_update); ?> existing item(s) missing identifiers</h2>

<p>These works are already in Wikidata, but don't have all the identifiers we know about.
You can add the missing ones in QuickStatements:</p>

<form action='https://tools.wmflabs.org/quickstatements/api.php' method='post' target='_blank'>
<input type='hidden' name='action' value='import' />
<input type='hidden' name='format' value='v1' />
<input type='hidden' name='temporary' value='1' />
<input type='hidden' name='openpage' value='1' />
<textarea style="padding:1em;font-size:1em;box-sizing: border-box;width:100%;" name="data" rows="10" ><?php

foreach ($to_update as $id => $quickstatements)
{
	echo $quickstatements;
}

?></textarea>
    <br />
    <button type="submit">Open in Quickstatements</button>
</form>
<?php
}

	if (count($have_already) > 0)
	{
		echo '<h2>' . count($have_already) . ' identifier(s) already in Wikidata</h2>';

		echo '<table>';
		echo '<tr><th>Identifier</th><th>Wikidata item</th></tr>';
		foreach ($have_already as $id => $qid)
		{
			echo '<tr>';
			echo '<td>' . htmlspecialchars($id) . '</td>';
			echo '<td>' . '<a href="https://www.wikidata.org/wiki/' . $qid . '" target="_blank">' . $qid . '</a>' . '</td>';
			echo '</tr>';
		}
		echo '</table>';
	}

	if (count($duplicates) > 0)
	{
		echo '<h2>' . count($duplicates) . ' duplicate identifier(s)</h2>';

		echo '<p>These refer to the same work as an identifier above, so they were only processed once.</p>';

		echo '<table>';
		echo '<tr><th>Identifier</th><th>Same work as</th></tr>';
		foreach ($duplicates as $id => $first)
		{
			echo '<tr>';
			echo '<td>' . htmlspecialchars($id) . '</td>';
			echo '<td>' . htmlspecialchars($first) . '</td>';
			echo '</tr>';
		}
		echo '</table>';
	}

	if (count($bad_identifier) > 0)
	{
		echo '<h2>Bad identifier(s)</h2>';
		echo '<ul>';
		foreach ($bad_identifier as $id)
		{
			echo '<li>' . htmlspecialchars($id) . '</li>';
		}
		echo '</ul>';
	}

?>

<h2>Add more</h2>

<form method="get">
	<textarea style="font-size:1em;box-sizing: border-box;width:100%;" id="ids"  name="ids" rows="5" placeholder="Enter identifiers here, one per line" ></textarea>
    <br />
    <button type="submit" name="add">Check and add</button>
</form>

</body>
</html>

<?php

}
else
{
	// display form

?>

<html>
<head>
	<meta charset="utf-8" />
	<title>BHL Wikidata</title>
	<style>
		body {
			font-family:sans-serif;
			padding:40px;
			color:#424242;
		}

	button {
		font-size:1em;
		background-color:blue;
		color:white;
		border:1px solid white;
		padding:1em;
		border-radius:4px;
	}

	a {
		text-decoration:none;
		color:rgb(28,27,168);
	}

	code {
		background-color:#f0f0f0;
		padding:0.1em 0.3em;
		border-radius:3px;
	}
	</style>
</head>
<body>
<h1>
	<a href=".">BHL to Wikidata</a>
</h1>

<p>A tool by Rod Page, code on <a href="https://github.com/rdmpage/bhl-wikidata" target="_blank">GitHub</a></p>

<p>This tool is inspired by <a href="https://sourcemd.toolforge.org/index_old.php">SourceMD</a> and works in much the same way.
Enter one or more identifiers, one per line. The tool checks whether they already exist in Wikidata,
if not it will create Quickstatements for them so you can add them yourself. If a work is already in
Wikidata but is missing an identifier we know about, the tool generates statements to add it.</p>

<p>You can enter:</p>
<ul>
	<li>a DOI, e.g. <code>10.24199/j.mmv.2004.61.3</code></li>
	<li>a BioStor id, e.g. <code>biostor:192990</code></li>
	<li>a BHL part id, e.g. <code>bhlpart:202055</code></li>
</ul>

<p>There is also another tool, <a href="cites.php">Cites Works</a> which adds citation links to works with DOIs.</p>

<form method="get">
	<textarea style="font-size:1em;box-sizing: border-box;width:100%;" id="ids"  name="ids" rows="10" placeholder="Enter identifiers here, one per line" ></textarea>
    <br />
    <!-- <button type="submit" name="check">Check</button> -->
    <button type="submit" name="add">Check and add</button>
</form>


</body>
</html>

<?php
}
?>
