<?php

//----------------------------------------------------------------------------------------
// BHL API helpers.
//
// Everything here talks to https://www.biodiversitylibrary.org/api2/ and hands back either
// a raw BHL "Result" object or a CSL-JSON work that the rest of the pipeline understands.

//----------------------------------------------------------------------------------------
// Call the BHL API. The API drops requests fairly often (returning an empty body or a
// response with no Result), so retry a couple of times before giving up.
function bhl_api($parameters, $retries = 3)
{
	$result = null;

	$api_key = getenv('BHL_API_KEY');

	if (!$api_key)
	{
		return $result;
	}

	$parameters['apikey'] = $api_key;
	$parameters['format'] = 'json';

	$url = 'https://www.biodiversitylibrary.org/api2/httpquery.ashx?' . http_build_query($parameters);

	for ($attempt = 0; $attempt < $retries; $attempt++)
	{
		if ($attempt > 0)
		{
			usleep(500000);
		}

		$json = get($url);

		if ($json == '')
		{
			continue;
		}

		$obj = json_decode($json);

		if ($obj && isset($obj->Result))
		{
			$result = $obj->Result;
			break;
		}
	}

	return $result;
}

//----------------------------------------------------------------------------------------
// Get a BHL part from any identifier BHL indexes, e.g. type "doi" or type "biostor".
function get_part_from_identifier($type, $value)
{
	$part = null;

	$result = bhl_api(array(
		'op' 	=> 'GetPartByIdentifier',
		'type'	=> $type,
		'value'	=> $value
	));

	if ($result && is_array($result) && count($result) > 0)
	{
		$part = $result[0];
	}

	return $part;
}

//----------------------------------------------------------------------------------------
// Get BHL part from DOI (typically an external id)
function get_part_from_doi($doi)
{
	return get_part_from_identifier('doi', strtolower($doi));
}

//----------------------------------------------------------------------------------------
// Get BHL part from a BioStor id
function get_part_from_biostor($biostor)
{
	return get_part_from_identifier('biostor', $biostor);
}

//----------------------------------------------------------------------------------------
// Get BHL part from a BHL part id
function get_part_from_bhl_part($id)
{
	$part = null;

	$result = bhl_api(array(
		'op' 		=> 'GetPartMetadata',
		'partid'	=> $id
	));

	if ($result)
	{
		$part = is_array($result) ? $result[0] : $result;
	}

	return $part;
}

//----------------------------------------------------------------------------------------
// BHL item metadata (cached for the life of the request)
function bhl_item_metadata($item_id)
{
	static $cache = array();

	if (!isset($cache[$item_id]))
	{
		$result = bhl_api(array(
			'op' 		=> 'GetItemMetadata',
			'itemid'	=> $item_id,
			'pages'		=> 'f',
			'ocr'		=> 'f',
			'parts'		=> 'f'
		));

		$cache[$item_id] = $result ? (is_array($result) ? $result[0] : $result) : null;
	}

	return $cache[$item_id];
}

//----------------------------------------------------------------------------------------
// BHL title metadata (cached for the life of the request)
function bhl_title_metadata($title_id)
{
	static $cache = array();

	if (!isset($cache[$title_id]))
	{
		$result = bhl_api(array(
			'op' 		=> 'GetTitleMetadata',
			'titleid'	=> $title_id,
			'items'		=> 'f'
		));

		$cache[$title_id] = $result ? (is_array($result) ? $result[0] : $result) : null;
	}

	return $cache[$title_id];
}

//----------------------------------------------------------------------------------------
// Find the journal a part belongs to, by walking part -> item -> title. BHL title records
// carry both the ISSNs and (often) the Wikidata item for the journal, which saves us a
// lookup and avoids having to join across the two Wikidata query endpoints.
//
// Returns array('ISSN' => array(...), 'JOURNAL' => 'Qnnn', 'TitleID' => n)
function bhl_journal_from_part($part)
{
	$journal = array(
		'ISSN' 		=> array(),
		'JOURNAL' 	=> '',
		'TitleID'	=> null
	);

	if (!$part || !isset($part->ItemID))
	{
		return $journal;
	}

	$item = bhl_item_metadata($part->ItemID);

	if (!$item || !isset($item->PrimaryTitleID))
	{
		return $journal;
	}

	$journal['TitleID'] = $item->PrimaryTitleID;

	$title = bhl_title_metadata($item->PrimaryTitleID);

	if (!$title || !isset($title->Identifiers))
	{
		return $journal;
	}

	foreach ($title->Identifiers as $identifier)
	{
		switch ($identifier->IdentifierName)
		{
			case 'ISSN':
				$journal['ISSN'][] = strtoupper($identifier->IdentifierValue);
				break;

			case 'Wikidata':
				if (preg_match('/^Q\d+$/', $identifier->IdentifierValue))
				{
					$journal['JOURNAL'] = $identifier->IdentifierValue;
				}
				break;

			default:
				break;
		}
	}

	return $journal;
}

//----------------------------------------------------------------------------------------
// The DOI BHL holds for a part, if any
function bhl_part_doi($part)
{
	$doi = '';
	
	if (!$part)
	{
		return $doi;
	}
	
	if (isset($part->Identifiers) && is_array($part->Identifiers))
	{
		foreach ($part->Identifiers as $identifier)
		{
			if ($identifier->IdentifierName == 'DOI' && trim($identifier->IdentifierValue) != '')
			{
				$doi = strtolower(trim($identifier->IdentifierValue));
			}
		}
	}
	
	if ($doi == '' && isset($part->Doi) && trim($part->Doi) != '')
	{
		$doi = strtolower(trim($part->Doi));
	}
	
	return $doi;
}

//----------------------------------------------------------------------------------------
// Split a BHL creator name ("Poe, Steven") into CSL family/given. Corporate authors and
// anything we can't split become a literal name.
function bhl_author_to_csl($author)
{
	$csl = new stdClass;

	$name = isset($author->Name) ? trim($author->Name) : '';

	// BHL names often carry trailing punctuation and life dates
	$name = preg_replace('/,\s*\d{4}\s*-\s*(\d{4})?\.?$/u', '', $name);
	$name = trim($name, " \t\n\r\0\x0B,.");

	if ($name == '')
	{
		return null;
	}

	if (preg_match('/^(?<family>[^,]+),\s*(?<given>.+)$/u', $name, $m))
	{
		$csl->family = trim($m['family']);
		$csl->given = trim($m['given']);
	}
	else
	{
		$csl->literal = $name;
	}

	if (isset($author->CreatorID) && $author->CreatorID)
	{
		$csl->BHL = $author->CreatorID;
	}

	return $csl;
}

//----------------------------------------------------------------------------------------
// Convert a BHL part record into a CSL-JSON work of the shape csljson_to_wikidata expects.
// Used for BHL/BioStor records that have no DOI, so there is no Crossref record to fetch.
function bhl_part_to_csljson($part)
{
	$work = null;

	if (!$part || !isset($part->Title) || trim($part->Title) == '')
	{
		return $work;
	}

	$message = new stdClass;

	$message->type = 'article-journal';
	$message->title = trim($part->Title);

	if (isset($part->ContainerTitle) && trim($part->ContainerTitle) != '')
	{
		$message->{'container-title'} = trim($part->ContainerTitle);
	}

	if (isset($part->Volume) && trim($part->Volume) != '')
	{
		$message->volume = trim($part->Volume);
	}

	// The part-level Issue is often empty even when the pages carry one
	$issue = isset($part->Issue) ? trim($part->Issue) : '';

	if ($issue == '' && isset($part->Pages) && is_array($part->Pages) && count($part->Pages) > 0)
	{
		if (isset($part->Pages[0]->Issue))
		{
			$issue = trim($part->Pages[0]->Issue);
		}
	}

	if ($issue != '')
	{
		$message->issue = $issue;
	}

	// Pages. StartPageNumber/EndPageNumber are cleaner than PageRange, which uses "47--55"
	$page = '';

	if (isset($part->StartPageNumber) && trim($part->StartPageNumber) != '')
	{
		$page = trim($part->StartPageNumber);

		if (isset($part->EndPageNumber) && trim($part->EndPageNumber) != '')
		{
			$page .= '-' . trim($part->EndPageNumber);
		}
	}
	else if (isset($part->PageRange) && trim($part->PageRange) != '')
	{
		$page = preg_replace('/\s*--\s*/', '-', trim($part->PageRange));
	}

	if ($page != '')
	{
		$message->page = $page;
	}

	// Date. BHL gives us anything from "2015" to "2015-03-01" to "1899-1900"
	$year = '';

	if (isset($part->Date) && preg_match('/(?<year>\d{4})/', $part->Date, $m))
	{
		$year = $m['year'];
	}

	if ($year == '' && isset($part->Pages) && is_array($part->Pages) && count($part->Pages) > 0)
	{
		if (isset($part->Pages[0]->Year) && preg_match('/(?<year>\d{4})/', $part->Pages[0]->Year, $m))
		{
			$year = $m['year'];
		}
	}

	if ($year != '')
	{
		$message->issued = new stdClass;
		$message->issued->{'date-parts'} = array(array((int)$year));
	}

	// Authors
	if (isset($part->Authors) && is_array($part->Authors))
	{
		$authors = array();

		foreach ($part->Authors as $author)
		{
			$csl = bhl_author_to_csl($author);

			if ($csl)
			{
				$authors[] = $csl;
			}
		}

		if (count($authors) > 0)
		{
			$message->author = $authors;
		}
	}

	// Identifiers
	if (isset($part->PartID))
	{
		$message->BHLPART = $part->PartID;
	}

	if (isset($part->StartPageID))
	{
		$message->BHL = $part->StartPageID;
	}

	if (isset($part->ItemID))
	{
		$message->ItemID = $part->ItemID;
	}

	if (isset($part->Identifiers) && is_array($part->Identifiers))
	{
		foreach ($part->Identifiers as $identifier)
		{
			switch ($identifier->IdentifierName)
			{
				case 'BioStor':
					$message->BIOSTOR = $identifier->IdentifierValue;
					break;

				case 'DOI':
					$message->DOI = strtolower($identifier->IdentifierValue);
					break;

				case 'JSTOR':
					$message->JSTOR = $identifier->IdentifierValue;
					break;

				case 'OCLC':
					$message->OCLC = $identifier->IdentifierValue;
					break;

				default:
					break;
			}
		}
	}

	if (!isset($message->DOI) && isset($part->Doi) && trim($part->Doi) != '')
	{
		$message->DOI = strtolower(trim($part->Doi));
	}

	// Journal. The ISSN lets csljson_to_wikidata link the container, and the Wikidata id
	// (when BHL has one) lets us skip that lookup entirely.
	$journal = bhl_journal_from_part($part);

	if (count($journal['ISSN']) > 0)
	{
		$message->ISSN = $journal['ISSN'];
	}

	if ($journal['JOURNAL'] != '')
	{
		$message->JOURNAL = $journal['JOURNAL'];
	}

	$work = new stdClass;
	$work->message = $message;

	return $work;
}

?>
