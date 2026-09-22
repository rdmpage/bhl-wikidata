<?php

error_reporting(E_ALL);

require_once 'vendor/autoload.php';
require_once(dirname(__FILE__) . '/bhl.php');
use LanguageDetection\Language;
use Biblys\Isbn\Isbn as Isbn;

//----------------------------------------------------------------------------------------
// Convert CSL author name to a simple string
function csl_author_to_name($author)
{
	$name = '';	
	
	// Get name as string
	$parts = array();
	if (isset($author->given))
	{
		$parts[] = $author->given;
	}
	
	if (isset($author->family))
	{
		$parts[] = $author->family;
	}
	
	if (isset($author->suffix))
	{
		$parts[] = $author->suffix;
	}
		
	if (count($parts) > 0)
	{								
		$name = join(' ', $parts);	
		$name = preg_replace('/\s\s+/u', ' ', $name);
	}
	else
	{
		if (isset($author->literal))
		{
			$name = $author->literal;
		}								
	}
	
	return $name;
}


//----------------------------------------------------------------------------------------
// Extract type of work from title
function types_from_title(&$w, $title)
{
	// errata
	
	if (preg_match('/^ERRATA\b/i', $title))
	{
		$w[] = array('P31' => 'Q1348305');	
		
		if (preg_match('/^ERRATA ET ADDENDA/i', $title))
		{
			$w[] = array('P31' => 'Q352858');	
		}
	
	}
}	

//----------------------------------------------------------------------------------------
function nice_strip_tags($str)
{
	$str = preg_replace('/</u', ' <', $str);
	$str = preg_replace('/>/u', '> ', $str);
	
	$str = strip_tags($str);
	
	$str = preg_replace('/&amp;/u', '&', $str);
	
	$str = preg_replace('/\s\s+/u', ' ', $str);
	
	$str = preg_replace('/^\s+/u', '', $str);
	$str = preg_replace('/\s+$/u', '', $str);
	
	return $str;
	
}

//----------------------------------------------------------------------------------------
// trim a string nicely
function nice_shorten($str, $length = 250) {
	if (mb_strlen($str) > $length)
	{
		$str = mb_substr($str, 0, $length - 1);
		
		$pos = mb_strrpos($str, ' ');
		if ($pos === false) {
		} else {
			$str = mb_substr($str, 0, $pos);		
		}
		
		$str .= '…';	
	}

	return $str;
}


//----------------------------------------------------------------------------------------
// Debugging: record every external call made by get(). Off unless BHL_WIKIDATA_PROFILE is set.
// Call get_profile() to retrieve the log.
function profiling_enabled()
{
	return getenv('BHL_WIKIDATA_PROFILE') ? true : false;
}

//----------------------------------------------------------------------------------------
function get_profile(&$log = null)
{
	static $calls = array();

	if ($log !== null)
	{
		$calls[] = $log;
	}

	return $calls;
}

//----------------------------------------------------------------------------------------
// How many requests have failed outright (timed out, or couldn't connect) this run.
//
// This matters because a lookup that failed tells us nothing, which is not at all the same
// as telling us there is no match. Treating the two alike would have us propose creating an
// item that already exists.
function get_failure_count($increment = false)
{
	static $count = 0;

	if ($increment)
	{
		$count++;
	}

	return $count;
}

//----------------------------------------------------------------------------------------
// How long to wait on a lookup we can manage without.
//
// Resolving an author to an item is a nicety: if it doesn't come back we fall back to the
// author's name as a string, which is a perfectly good statement. Checking whether a work
// is already in Wikidata is not a nicety, so that keeps the full timeout. Failing fast on
// the optional work leaves more of the request budget for the parts we depend on.
define('BHL_WIKIDATA_ENRICHMENT_TIMEOUT', 8);

//----------------------------------------------------------------------------------------
// Did the last "is this already in Wikidata?" check manage to complete? Set by
// csljson_to_wikidata, read by callers before they act on a CREATE.
function wikidata_check_was_complete($set = null)
{
	static $complete = true;

	if ($set !== null)
	{
		$complete = $set ? true : false;
	}

	return $complete;
}

//----------------------------------------------------------------------------------------
// $timeout caps how long a single request may take, in seconds. Without a cap one slow
// reply (the Wikidata query service is erratic, and has taken 40s for a query that usually
// takes under a second) can eat the whole PHP execution limit and kill the request. With
// one we simply get no answer for that lookup and carry on, e.g. falling back to an author
// name string instead of an author item. Override with BHL_WIKIDATA_TIMEOUT.
function get($url, $user_agent='', $content_type = '', $timeout = 0)
{
	$data = null;

	$profile = profiling_enabled();
	$start = $profile ? microtime(true) : 0;

	if ($timeout <= 0)
	{
		$timeout = getenv('BHL_WIKIDATA_TIMEOUT') ? (int)getenv('BHL_WIKIDATA_TIMEOUT') : 20;
	}

	$opts = array(
	  CURLOPT_URL =>$url,
	  CURLOPT_FOLLOWLOCATION => TRUE,
	  CURLOPT_RETURNTRANSFER => TRUE,
	  
		CURLOPT_SSL_VERIFYHOST=> FALSE,
		CURLOPT_SSL_VERIFYPEER=> FALSE,
	  
		CURLOPT_TIMEOUT => $timeout,
		CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
	  
	);

	// The Wikidata query service throttles requests that don't identify themselves, so
	// always send a descriptive user agent (see meta.wikimedia.org/wiki/User-Agent_policy)
	if ($user_agent == '')
	{
		$user_agent = 'bhl-wikidata/1.0 (https://github.com/rdmpage/bhl-wikidata; rdmpage@gmail.com)';
	}

	$headers = array('User-agent: ' . $user_agent);

	if ($content_type != '')
	{
		$headers[] = 'Accept: ' . $content_type;
	}

	$opts[CURLOPT_HTTPHEADER] = $headers;

	$ch = curl_init();
	curl_setopt_array($ch, $opts);
	$data = curl_exec($ch);
	$info = curl_getinfo($ch);
	curl_close($ch);

	if ($data === false)
	{
		get_failure_count(true);
	}

	if ($profile)
	{
		$entry = array(
			'url'      => $url,
			'seconds'  => microtime(true) - $start,
			'http'     => isset($info['http_code']) ? $info['http_code'] : 0,
			'bytes'    => is_string($data) ? strlen($data) : 0,
			'timedout' => ($data === false) ? 1 : 0,
		);

		get_profile($entry);
	}

	return $data;
}

//----------------------------------------------------------------------------------------
// BHL ItemID to Wikidata item
function wikidata_from_bhl_item($ItemID)
{
	$cache = array(
	);
	
	$item = '';
	
	if (isset($cache[$ItemID]))
	{
		$item = $ItemID;
	}
	
	if ($item == '')
	{
		$metadata = bhl_item_metadata($ItemID);

		if ($metadata && isset($metadata->PrimaryTitleID))
		{
			// BHL title records often name the Wikidata item for the journal outright
			$title = bhl_title_metadata($metadata->PrimaryTitleID);

			if ($title && isset($title->Identifiers))
			{
				foreach ($title->Identifiers as $identifier)
				{
					if ($identifier->IdentifierName == 'Wikidata'
						&& preg_match('/^Q\d+$/', $identifier->IdentifierValue))
					{
						$item = $identifier->IdentifierValue;
						break;
					}
				}
			}

			// otherwise assume the title has a DOI
			if ($item == '')
			{
				$doi = '10.5962/BHL.TITLE.' . $metadata->PrimaryTitleID;
				$item = wikidata_item_from_doi($doi);
			}
		}
	}

	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this funder with a Crossref funder DOI?
function wikidata_funder_from_doi($doi)
{
	$item = '';
	
	$id = $doi;
	$id = strtoupper(str_replace('10.13039/', '', $id));
	
	$sparql = 'SELECT * WHERE { ?funder wdt:P3153 "' . $id . '" }';
	
	// echo $sparql . "\n";
	
	$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->funder->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this Internet Archive item?
function wikidata_item_from_internet_archive($ia)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P724 "' . $ia . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this Google Book?
function wikidata_item_from_google_book($gb)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P675 "' . $gb . '" }';
	
	$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
function normalize_doi_key($doi)
{
	if (!is_string($doi))
	{
		return '';
	}
	
	return mb_strtoupper(trim($doi));
}


//----------------------------------------------------------------------------------------
function fetch_wikidata_items_for_dois($dois)
{
	$result = array();
	
	if (count($dois) == 0)
	{
		return $result;
	}
	
	$values = array();
	
	foreach ($dois as $doi)
	{
		$values[] = '"' . addcslashes($doi, "\\\"") . '"';
	}
	
	$sparql = 'SELECT ?doi ?work WHERE {';
	$sparql .= ' VALUES ?doi { ' . join(' ', $values) . ' }';
	$sparql .= ' ?work wdt:P356 ?doi .';
	$sparql .= ' }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
	
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			foreach ($obj->results->bindings as $binding)
			{
				if (isset($binding->doi->value) && isset($binding->work->value))
				{
					$key = normalize_doi_key($binding->doi->value);
					$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $binding->work->value);
					$result[$key] = $item;
				}
			}
		}
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------
// Does wikidata have these DOIs?
function wikidata_items_from_dois($dois)
{
	$result = array();
	static $cache = array();
	
	if (!is_array($dois))
	{
		return $result;
	}
	$pending = array();
	
	foreach ($dois as $doi)
	{
		$key = normalize_doi_key($doi);
		
		if ($key == '')
		{
			continue;
		}
		
		if (array_key_exists($key, $cache))
		{
			$result[$key] = $cache[$key];
		}
		else
		{
			$pending[$key] = $key;
		}
	}
	
	if (count($pending) == 0)
	{
		return $result;
	}
	
	$chunks = array_chunk(array_values($pending), 50);
	
	foreach ($chunks as $chunk)
	{
		$chunk_map = fetch_wikidata_items_for_dois($chunk);
		
		foreach ($chunk_map as $key => $item)
		{
			$cache[$key] = $item;
			$result[$key] = $item;
		}
		
		foreach ($chunk as $doi)
		{
			$key = normalize_doi_key($doi);
			
			if ($key == '')
			{
				continue;
			}
			
			if (!array_key_exists($key, $cache))
			{
				$cache[$key] = '';
			}
			
			if (!array_key_exists($key, $result))
			{
				$result[$key] = $cache[$key];
			}
		}
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this DOI?
function wikidata_item_from_doi($doi)
{
	$item = '';
	
	$map = wikidata_items_from_dois(array($doi));
	$key = mb_strtoupper(trim((string)$doi));
	
	if ($key != '' && isset($map[$key]))
	{
		$item = $map[$key];
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this PMC?
function wikidata_item_from_pmc($pmc)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P932 "' . str_replace('PMC', '', $pmc) . '" }';
	
	//echo $sparql . "\n";
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
	
	//echo $json;
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this URL?
function wikidata_item_from_url($url)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P953 <' . $url . '> }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
	
	
	//echo $sparql;
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this JSTOR id?
function wikidata_item_from_jstor($jstor)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P888 "' . $jstor . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this PMID?
function wikidata_item_from_pmid($pmid)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P698 "' . $pmid . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}


//----------------------------------------------------------------------------------------
// Does wikidata have this BHL part id?
function wikidata_item_from_bhl_part($bhl_part)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P6535 "' . $bhl_part . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}


//----------------------------------------------------------------------------------------
// Does wikidata have this BioStor id?
function wikidata_item_from_biostor($biostor)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P5315 "' . $biostor . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this CNKI?
function wikidata_item_from_cnki($cnki)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P6769 "' . $cnki . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this PERSEE?
function wikidata_item_from_persee_article($perse)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P8758 "' . $perse . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this DIALNET?
function wikidata_item_from_dialnet($dialnet)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P1610 "' . $dialnet . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this CINII?
function wikidata_item_from_cinii($cinii)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P2409 "' . $cinii . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this Zoobank pub?
function wikidata_item_from_zoobank($zoobank)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P2007 "' . $zoobank . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this PDF
function wikidata_item_from_pdf($pdf)
{
	$item = '';
	
	// URI
	$sparql = 'SELECT * WHERE { ?work wdt:P953 <' . $pdf . '> }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
			
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this Handle id?
function wikidata_item_from_handle($handle)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P1184 "' . $handle . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this SUDOC id?
function wikidata_item_from_sudoc($sudoc)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P1025 "' . $sudoc . '" }';
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Do we have a journal with this ISSN?
function wikidata_item_from_issn($issn)
{
	$cached_issn = array(
		'0067-0464' => 'Q15214730', // Records of the Auckland Institute and Museum
		'0001-804X' => 'Q58814054', // Adansonia nouvelle série		
		'0003-049X' => 'Q6087079', // Proceedings of the American Philosophical Society
		'0199-9818' => 'Q6087076', // Proceedings of the American Academy of Arts and Sciences
		'0097-3157' => 'Q11134281', // Proceedings of The Academy of Natural Sciences of Philadelphia
		'2410-0226' => 'Q18649566', // Zoosystematica Rossica
		'0424-7086' => 'Q15766885', // Medical Entomology and Zoology
		'0027-0113' => 'Q27887126', // Comunicaciones Zoológicas Del Museo de Historia Natural de Montevideo
		'0036-7575' => 'Q21385818', // Mitteilungen der Schweizerischen Entomologischen Gesellschaft 
		'0373-2967' => 'Q5747392', // Candolea
		'2153-733X' => 'Q15314455', // Phytoneuron
		'1560-2745' => 'Q15765496', // Fungal Diversity
		'0001-6616' => 'Q15746639',
		'0006-7172'	=> 'Q15750918', // Bonner zoologische Beiträge
		'1148-8425' => 'Q37408733', // Bulletin du Muséum national d'histoire naturelle
		'2095-1787' => 'Q111386916', // Journal of Biosafety
		'0007-2745' => 'Q7720447', // The Bryologist
	);

	// Resolving an ISSN hits the main query endpoint and is often slow, so remember
	// what we've already looked up this run
	static $seen = array();

	$item = '';
	
	if (isset($cached_issn[$issn]))
	{
		$item = $cached_issn[$issn];
	}
	else if (isset($seen[$issn]))
	{
		$item = $seen[$issn];
	}
	else
	{
	
		$sparql = 'SELECT * WHERE { ?work wdt:P236 "' . strtoupper($issn) . '" }';
	
		$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
		$json = get($url, '', 'application/json');
	
		if ($json != '')
		{
			$obj = json_decode($json);
			if (isset($obj->results->bindings))
			{
				if (count($obj->results->bindings) != 0)	
				{
					$item = $obj->results->bindings[0]->work->value;
					$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
				}
			}
		}
	}
		
	$seen[$issn] = $item;
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Do we have a book with this ISBN-10?
function wikidata_item_from_isbn10($isbn10)
{
	$item = '';

	$isbns[] = Isbn::convertToIsbn10($isbn10);
	
	// print_r($isbns);
	
	foreach ($isbns as $id)
	{
		$sparql = 'SELECT * WHERE { ?work wdt:P212 "' . strtoupper($id) . '" }';

		$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
		$json = get($url, '', 'application/json');

		if ($json != '')
		{
			$obj = json_decode($json);
			if (isset($obj->results->bindings))
			{
				if (count($obj->results->bindings) != 0)	
				{
					$item = $obj->results->bindings[0]->work->value;
					$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
				}
			}
		}
	}
		
	return $item;
}

//----------------------------------------------------------------------------------------
// Do we have a book with this ISBN-13?
function wikidata_item_from_isbn13($isbn13)
{
	$item = '';
	
	$isbns[] = Isbn::convertToIsbn13($isbn13);
	
	//print_r($isbns);
	
	foreach ($isbns as $id)
	{
		$sparql = 'SELECT * WHERE { ?work wdt:P212 "' . strtoupper($id) . '" }';

		$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
		$json = get($url, '', 'application/json');

		if ($json != '')
		{
			$obj = json_decode($json);
			if (isset($obj->results->bindings))
			{
				if (count($obj->results->bindings) != 0)	
				{
					$item = $obj->results->bindings[0]->work->value;
					$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
				}
			}
		}
	}
		
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this OCLC ?
function wikidata_item_from_oclc($oclc)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P243 "' . $oclc . '" }';
	
	$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Does wikidata have this VIAF ?
function wikidata_item_from_viaf($viaf)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?work wdt:P214 "' . $viaf . '" }';
	
	$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}


//----------------------------------------------------------------------------------------
function wikidata_item_from_journal_name($name, $language = 'en')
{
	$item = '';
	
	// Try  description
	$sparql = 'SELECT * WHERE { ?item rdfs:label "' . addcslashes($name, '"') . '"@' . $language . ' . ?item wdt:P31 wd:Q5633421}';
	
	// echo $sparql . "\n";
	
	$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
	
	if ($json != '')
	{
		$obj = json_decode($json);
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->item->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
function normalize_orcid($orcid)
{
	if (!is_string($orcid))
	{
		return '';
	}

	return mb_strtoupper(trim(preg_replace('/https?:\/\/orcid\.org\//', '', $orcid)));
}

//----------------------------------------------------------------------------------------
// Look up a batch of ORCIDs in one SPARQL query. A paper with 40 authors was making 40
// separate round trips to WDQS, which is what caused the timeouts in issue #21.
function wikidata_items_from_orcids($orcids)
{
	$result = array();
	static $cache = array();

	if (!is_array($orcids))
	{
		return $result;
	}

	$pending = array();

	foreach ($orcids as $orcid)
	{
		$key = normalize_orcid($orcid);

		if ($key == '')
		{
			continue;
		}

		if (array_key_exists($key, $cache))
		{
			$result[$key] = $cache[$key];
		}
		else
		{
			$pending[$key] = $key;
		}
	}

	if (count($pending) == 0)
	{
		return $result;
	}

	$chunks = array_chunk(array_values($pending), 50);

	foreach ($chunks as $chunk)
	{
		$values = array();

		foreach ($chunk as $orcid)
		{
			$values[] = '"' . addcslashes($orcid, "\\\"") . '"';
		}

		$sparql = 'SELECT ?orcid ?author WHERE {';
		$sparql .= ' VALUES ?orcid { ' . join(' ', $values) . ' }';
		$sparql .= ' ?author wdt:P496 ?orcid .';
		$sparql .= ' }';

		$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
		$json = get($url, '', 'application/json', BHL_WIKIDATA_ENRICHMENT_TIMEOUT);

		// An ORCID matching more than one item is ambiguous, so track how many we saw
		$seen = array();

		if ($json != '')
		{
			$obj = json_decode($json);

			if (isset($obj->results->bindings))
			{
				foreach ($obj->results->bindings as $binding)
				{
					if (isset($binding->orcid->value) && isset($binding->author->value))
					{
						$key = normalize_orcid($binding->orcid->value);
						$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $binding->author->value);

						$seen[$key] = isset($seen[$key]) ? $seen[$key] + 1 : 1;

						// only accept an unambiguous match, as the unbatched code did
						$cache[$key] = ($seen[$key] == 1) ? $item : '';
					}
				}
			}
		}

		foreach ($chunk as $orcid)
		{
			$key = normalize_orcid($orcid);

			if (!array_key_exists($key, $cache))
			{
				$cache[$key] = '';
			}

			$result[$key] = $cache[$key];
		}
	}

	return $result;
}

//----------------------------------------------------------------------------------------
function wikidata_item_from_orcid($orcid)
{
	$item = '';

	$map = wikidata_items_from_orcids(array($orcid));
	$key = normalize_orcid($orcid);

	if ($key != '' && isset($map[$key]))
	{
		$item = $map[$key];
	}

	return $item;
}

//----------------------------------------------------------------------------------------
function wikidata_item_from_persee($perse)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?author wdt:P2732 "' . $perse . '" }';
	
	//echo $sparql . "\n";
	
	$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
	
	if ($json != '')
	{
		$obj = json_decode($json);
		
		//print_r($obj);
		
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) == 1)	
			{
				$item = $obj->results->bindings[0]->author->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
function wikidata_item_from_idref($id)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?author wdt:P269 "' . $id . '" }';
	
	//echo $sparql . "\n";
	
	$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
	
	if ($json != '')
	{
		$obj = json_decode($json);
		
		//print_r($obj);
		
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) == 1)	
			{
				$item = $obj->results->bindings[0]->author->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}


//----------------------------------------------------------------------------------------
function wikidata_item_from_wikispecies_author($wikispecies)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { VALUES ?article {<https://species.wikimedia.org/wiki/' . urlencode($wikispecies) . '> } ?article schema:about ?author . ?author wdt:P31 wd:Q5 . }';
	
	//echo $sparql . "\n";
	//echo urlencode($sparql) . "\n";
	
	$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
	
	if ($json != '')
	{
		$obj = json_decode($json);
		
		//print_r($obj);
		
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) == 1)	
			{
				$item = $obj->results->bindings[0]->author->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// Resolve a set of BHL creator ids to Wikidata items in one query.
//
// Parts routinely have several authors, and looking each one up on its own is slow enough
// to time the tool out, so batch them the same way ORCIDs are batched.
//
// Returns an array keyed by BHL creator id. As with the unbatched lookup, a creator id
// that matches more than one item is treated as no match.
function wikidata_items_from_bhl_creators($ids)
{
	$result = array();

	// Two levels of cache. $memory holds everything learnt this request, including misses.
	// $disk survives between requests: the same authors turn up over and over across BHL
	// parts, and resolving one hits the main Wikidata query endpoint, which is slow and
	// erratic. A match is effectively permanent so we keep it indefinitely; a miss only
	// holds until somebody creates that author in Wikidata, so we re-check it later.
	static $memory = array();
	static $disk = null;

	$filename = dirname(__FILE__) . '/creators.json';

	if ($disk === null)
	{
		$disk = bhl_creator_cache_load($filename);
	}

	// update-creators.php fetches every P4081 statement in Wikidata. While that dump is
	// recent we can answer from it alone, including for creator ids it doesn't list. Once
	// it goes stale we fall back to asking, so a forgotten rebuild degrades rather than
	// silently reporting everyone added since as unlinked.
	$dump_is_authoritative = isset($disk['updated']) && bhl_creator_miss_is_fresh($disk['updated']);

	if (!is_array($ids))
	{
		return $result;
	}

	$pending = array();

	foreach ($ids as $id)
	{
		$key = trim((string)$id);

		if ($key == '')
		{
			continue;
		}

		if (array_key_exists($key, $memory))
		{
			$result[$key] = $memory[$key];
		}
		else if (isset($disk['hits'][$key]))
		{
			$memory[$key] = $disk['hits'][$key];
			$result[$key] = $memory[$key];
		}
		else if (isset($disk['misses'][$key]) && bhl_creator_miss_is_fresh($disk['misses'][$key]))
		{
			$memory[$key] = '';
			$result[$key] = '';
		}
		else if ($dump_is_authoritative)
		{
			// The dump lists every BHL creator id in Wikidata, so not being in it is the
			// answer, not a reason to go and ask
			$memory[$key] = '';
			$result[$key] = '';
		}
		else
		{
			$pending[$key] = $key;
		}
	}

	if (count($pending) == 0)
	{
		return $result;
	}

	$today = date('Y-m-d');
	$dirty = false;

	$chunks = array_chunk(array_values($pending), 50);

	foreach ($chunks as $chunk)
	{
		$values = array();

		foreach ($chunk as $id)
		{
			$values[] = '"' . addcslashes($id, "\\\"") . '"';
		}

		$sparql = 'SELECT ?creator ?author WHERE {';
		$sparql .= ' VALUES ?creator { ' . join(' ', $values) . ' }';
		$sparql .= ' ?author wdt:P4081 ?creator .';
		$sparql .= ' }';

		$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
		$json = get($url, '', 'application/json', BHL_WIKIDATA_ENRICHMENT_TIMEOUT);

		// If the query timed out we know nothing about these ids. Don't record that as a
		// miss, or a slow moment would be cached as "not in Wikidata".
		if ($json == '')
		{
			foreach ($chunk as $id)
			{
				$result[$id] = '';
			}

			continue;
		}

		// A creator id matching more than one item is ambiguous, so track how many we saw
		$seen = array();

		$obj = json_decode($json);

		if (isset($obj->results->bindings))
		{
			foreach ($obj->results->bindings as $binding)
			{
				if (isset($binding->creator->value) && isset($binding->author->value))
				{
					$key = trim($binding->creator->value);
					$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $binding->author->value);

					$seen[$key] = isset($seen[$key]) ? $seen[$key] + 1 : 1;

					// only accept an unambiguous match, as the unbatched code did
					$memory[$key] = ($seen[$key] == 1) ? $item : '';
				}
			}
		}

		foreach ($chunk as $id)
		{
			if (!array_key_exists($id, $memory))
			{
				$memory[$id] = '';
			}

			if ($memory[$id] == '')
			{
				$disk['misses'][$id] = $today;
			}
			else
			{
				$disk['hits'][$id] = $memory[$id];
				unset($disk['misses'][$id]);
			}

			$dirty = true;

			$result[$id] = $memory[$id];
		}
	}

	if ($dirty)
	{
		@file_put_contents(
			$filename,
			json_encode($disk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			LOCK_EX);
	}

	return $result;
}

//----------------------------------------------------------------------------------------
// Load creators.json.
//
// Shape is { "updated": ..., "hits": { creator: item }, "misses": { creator: date } }.
// Hits are kept indefinitely: a P4081 statement, once made, effectively stays. Misses are
// only true until somebody links that author, so they carry the date we last checked.
function bhl_creator_cache_load($filename)
{
	$cache = array('hits' => array(), 'misses' => array());

	if (!file_exists($filename))
	{
		return $cache;
	}

	$obj = json_decode(@file_get_contents($filename), true);

	if (!is_array($obj))
	{
		return $cache;
	}

	if (isset($obj['hits']) && is_array($obj['hits']))
	{
		$cache['hits'] = $obj['hits'];
	}

	if (isset($obj['misses']) && is_array($obj['misses']))
	{
		$cache['misses'] = $obj['misses'];
	}

	if (isset($obj['updated']))
	{
		$cache['updated'] = $obj['updated'];
	}

	return $cache;
}

//----------------------------------------------------------------------------------------
// Should we still believe a cached miss, or is it time to look again?
function bhl_creator_miss_is_fresh($checked)
{
	$time = strtotime((string)$checked);

	if ($time === false)
	{
		return false;
	}

	return (time() - $time) < (30 * 24 * 60 * 60);
}

//----------------------------------------------------------------------------------------
// Does Wikidata have an author with this BHL creator id?
function wikidata_item_from_bhl_creator($id)
{
	$item = '';

	$map = wikidata_items_from_bhl_creators(array($id));
	$key = trim((string)$id);

	if ($key != '' && isset($map[$key]))
	{
		$item = $map[$key];
	}

	return $item;
}

//----------------------------------------------------------------------------------------
function wikidata_item_from_zoobank_author($id)
{
	$item = '';
	
	$sparql = 'SELECT * WHERE { ?author wdt:P2006 "' . strtoupper($id) . '" }';
	
	//echo $sparql . "\n";
	
	$url = 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
	
	if ($json != '')
	{
		$obj = json_decode($json);
		
		//print_r($obj);
		
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) == 1)	
			{
				$item = $obj->results->bindings[0]->author->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}


//----------------------------------------------------------------------------------------
// Convert a csl json object to Wikidata quickstatments
function csljson_to_wikidata($work, $check = true, $update = true, $languages_to_detect = array('en'), $source = array())
{

	$MAX_LABEL_LENGTH = 250;

	$quickstatements = '';
	
	$description = '';
	
	// Map language codes to Wikidata items
	$language_map = array(
		'ca' => 'Q7026',
		'cs' => 'Q9056',
		'da' => 'Q9035',
		'de' => 'Q188',
		'en' => 'Q1860',
		'es' => 'Q1321',
		'fr' => 'Q150',
		'hu' => 'Q9067',
		'it' => 'Q652',
		'ja' => 'Q5287',
		'la' => 'Q397',
		'nl' => 'Q7411',
		'pl' => 'Q809',
		'pt' => 'Q5146',
		'ru' => 'Q7737',
		'sv' => 'Q9027',
		'th' => 'Q9217',
		'un' => 'Q22282914', 
		'vi' => 'Q9199',
		'zh' => 'Q7850',		
	);
	
	// Journals that are Portuguese (or contain signifcant Portuguese content)
	$pt_issn = array(
		'2178-0579', 
		'2175-7860', 
		'1983-0572',
		'1808-2688',
		'2175-7860', 
		'0101-8175', 
		'1806-969X',
		'0328-0381',
		'0074-0276',
		'0065-6755',
		'2317-6105',
		'0034-7108',
		);
	
	// labels don't get references 
	$properties_to_ignore = array();
	
	$properties_to_ignore = array(
		'P724',
		'P953',
		'P407', // language of work is almost never set by the source
		'P1922',
		'P6535', // credit BHL separately
		'P687', // credit BHL separately
		'P5315', // credit BHL separately
	); // e.g., when adding PDFs or IA to records from JSTOR
	
	// Is record sane?
	if (!isset($work->message->title))
	{
		return;
	}

	if (isset($work->message->title))
	{
		if (is_array($work->message->title) && count($work->message->title) == 0)
		{
			return;
		}
		else
		{
			if ($work->message->title == '')
			{
				return;
			}
		}
	}

	// Do we have this already in wikidata?
	$item = '';
	
	// A lookup that fails tells us nothing. Remember whether all of them got through, so
	// the caller can tell "no match" apart from "couldn't find out" and doesn't propose
	// creating something that already exists.
	$failures_before_check = get_failure_count();
	
	wikidata_check_was_complete(true);
	
	if ($check)
	{
	
		// DOI
		if (isset($work->message->DOI))
		{
			$item = wikidata_item_from_doi($work->message->DOI);
		}
		
		// PMID
		if (isset($work->message->PMID))
		{
			$item = wikidata_item_from_pmid($work->message->PMID);
		}

		// PMC
		if (isset($work->message->PMC))
		{
			$item = wikidata_item_from_pmc($work->message->PMC);
		}
		
		// ZooBank
		if (isset($work->message->ZOOBANK))
		{
			$item = wikidata_item_from_zoobank($work->message->ZOOBANK);
		}
			
		// JSTOR
		if ($item == '')
		{
			if (isset($work->message->JSTOR))
			{
				$item = wikidata_item_from_jstor($work->message->JSTOR);
			
			}
		}	
				
		// HANDLE
		if ($item == '')
		{
			if (isset($work->message->HANDLE))
			{
				$item = wikidata_item_from_handle($work->message->HANDLE);
			
			}
		}					

		// SUDOC
		if ($item == '')
		{
			if (isset($work->message->SUDOC))
			{
				$item = wikidata_item_from_sudoc($work->message->SUDOC);
			
			}
		}					
	
		// BioStor
		if ($item == '')
		{
			if (isset($work->message->BIOSTOR))
			{
				$item = wikidata_item_from_biostor($work->message->BIOSTOR);
			}
		}

		// BHL part
		if ($item == '')
		{
			if (isset($work->message->BHLPART))
			{
				$item = wikidata_item_from_bhl_part($work->message->BHLPART);
			}
		}

		// CNKI
		if ($item == '')
		{
			if (isset($work->message->CNKI))
			{
				$item = wikidata_item_from_cnki($work->message->CNKI);
			}
		}		
		
		if ($item == '')
		{
			if (isset($work->message->PERSEE))
			{
				$item = wikidata_item_from_persee_article($work->message->PERSEE);
			}
		}		
		
		
		if ($item == '')
		{
			if (isset($work->message->DIALNET))
			{
				$item = wikidata_item_from_dialnet($work->message->DIALNET);
			}
		}		

		if ($item == '')
		{
			if (isset($work->message->CINII))
			{
				$item = wikidata_item_from_cinii($work->message->CINII);
			}
		}		
	
		// PDF
		if ($item == '')
		{
			if (isset($work->message->link))
			{
				foreach ($work->message->link as $link)
				{
					if ($link->{'content-type'} == 'application/pdf')
					{
						$item = wikidata_item_from_pdf($link->URL);
					}
				}
			}
		}
		
		// URL
		if ($item == '')
		{
			if (isset($work->message->URL))
			{
				$item = wikidata_item_from_url($work->message->URL);
			}
		}
		
		
		// OpenURL. This is the only check that catches works which are in Wikidata but
		// carry none of the identifiers we were given, e.g. a BioStor record whose item
		// was created from a scan and never given a BioStor id.
		if ($item == '')
		{
			$volume = $spage = $year = '';

			if (isset($work->message->volume))
			{
				$volume = $work->message->volume;
			}

			if (isset($work->message->page))
			{
				if (preg_match('/^(?<spage>\d+)(-\d+)?/', $work->message->page, $m))
				{
					$spage = $m['spage'];
				}
			}

			if (isset($work->message->{'issued'}))
			{
				$year = $work->message->{'issued'}->{'date-parts'}[0][0];
			}

			if ($volume != '' && $spage != '' && $year != '')
			{
				// If we already know the container item (BHL tells us) use it directly.
				// Looking the ISSNs up as well would only rediscover the same container,
				// and resolving an ISSN is one of the slowest queries we make.
				if (isset($work->message->JOURNAL))
				{
					$item = wikidata_item_from_openurl_container($work->message->JOURNAL, $volume, $spage, $year);
				}
				else if (isset($work->message->ISSN))
				{
					$issns = is_array($work->message->ISSN) ? $work->message->ISSN : array($work->message->ISSN);

					foreach ($issns as $issn)
					{
						if ($item == '')
						{
							$item = wikidata_item_from_openurl_issn($issn, $volume, $spage, $year);
						}
					}
				}
			}
		}

		wikidata_check_was_complete(get_failure_count() == $failures_before_check);
	}
	
	if ($item != '')
	{
		// already exists, if $update is false then exit		
		if (!$update)
		{
			return $item;
		}	
	}
	
	
	if ($item == '')
	{
		$item = 'LAST';
	}
	
	$w = array();
			
	$wikidata_properties = array(
		'type'					=> 'P31',
		'BHL' 					=> 'P687',
		'BHLPART' 				=> 'P6535',
		'BIOSTOR' 				=> 'P5315',
		'CINII'					=> 'P2409',
		'CNKI'					=> 'P6769',
		'DIALNET'				=> 'P1610',
		'DOI' 					=> 'P356',
		'HANDLE'				=> 'P1184',
		'JSTOR'					=> 'P888',
		'PMID'					=> 'P698',
		'PMC' 					=> 'P932',
		'SUDOC' 				=> 'P1025',
		'URL'					=> 'P953',	// https://twitter.com/EvoMRI/status/1062785719096229888
		'title'					=> 'P1476',	
		'volume' 				=> 'P478',
		'issue' 				=> 'P433',
		'page' 					=> 'P304',
		'PERSEE'				=> 'P8758',
		'PDF'					=> 'P953',
		'ARCHIVE'				=> 'P724',
		'ZOOBANK_PUBLICATION' 	=> 'P2007',
		'abstract'				=> 'P1922', // first line
		'article-number'		=> 'P1545', // series ordinal
	);
	
	// Need to think how to handle multi tag	
	foreach ($work->message as $k => $v)
	{	
		switch ($k)
		{
			//----------------------------------------------------------------------------
			case 'type':
				switch ($v)
				{
					case 'dataset':
						$w[] = array('P31' => 'Q1172284');												
						$description = "Dataset";
						break;
				
					case 'dissertation':
						// default is thesis
						$dissertation_type = 'Q1266946';
						
						if (isset($work->message->degree))
						{
							switch ($work->message->degree[0])
							{
								case 'PhD Thesis':
									$dissertation_type = 'Q187685';
									break;
									
								default:
									break;
							}
						}					
						$w[] = array('P31' => $dissertation_type);						
						$description = "Dissertation";
						break;
												
					case 'book-chapter':
						$w[] = array('P31' => 'Q1980247');						
						$description = "Book chapter";
						break;	
												
					case 'book':
						$w[] = array('P31' => 'Q47461344'); // written work						
						$description = "Book";
						break;		

					case 'edited-book':
						$w[] = array('P31' => 'Q1711593'); // edited volume						
						$description = "Edited book";
						break;		
						
					case 'monograph':		
						$w[] = array('P31' => 'Q571'); // book
						$w[] = array('P31' => 'Q193495'); // monograph						
						$description = "Monograph";
						break;	
						
					case 'reference-book':
						$w[] = array('P31' => 'Q47461344'); // written work						
						$description = "Book";
						break;	
						
					case 'report':	
						$w[] = array('P31' => 'Q10870555'); // report					
						$description = "Report";
						break;							
													
					case 'article-journal':
					case 'journal-article':
					default:
						$w[] = array('P31' => 'Q13442814');						
						$description = "Scholarly article";
						break;											
				}
				break;
				
		
			case 'subtitle':
				$subtitle = $v;
				if (is_array($v))
				{
					if (count($v) == 0)
					{
						$subtitle = '';
					}
					else
					{
						$subtitle = $v[0];
					}
				}				
			
				if ($subtitle != '')
				{
					$ld = new Language($languages_to_detect);						
					$language = $ld->detect($subtitle)->__toString();
				
					$w[] = array('P1680' => $language . ':' . '"' . $subtitle . '"');
				}			
				break;
		
			//----------------------------------------------------------------------------
			case 'title':			
				// Handle multiple languages
				$done = false;
				
				$english_label = '';
				$last_label = '';
				
				if (isset($work->message->multi))
				{
					if (isset($work->message->multi->_key->title))
					{					
						foreach ($work->message->multi->_key->title as $language => $v)
						{
							$v = preg_replace('/\s+$/u', '', $v);
							$v = nice_strip_tags($v);
													
							// title
							$w[] = array($wikidata_properties[$k] => $language . ':' . '"' . $v . '"');

							// label
							$last_label = nice_shorten($v, $MAX_LABEL_LENGTH);
							$w[] = array('L' . $language => '"' . $last_label . '"');
							
							if ($language == 'en')
							{
								$english_label = $last_label;
							}
						}					
						$done = true;
					}					
				}
				
				if ($done && $english_label == '' && $always_english_label)
				{
					// make an English label for display
					$w[] = array('Len' => '"' . $last_label . '"');															
				}
			
				if (!$done)
				{		
					$title = $v;
					if (is_array($v))
					{
						if (count($v) == 0)
						{
							$title = '';
						}
						else
						{
							$title = $v[0];
						}
					}				
					
					if ($title != '')
					{				
						$title = nice_strip_tags($title);
						
						// J-Stage fixes
						if (preg_match('/(\^\|\^[a-z]+);/i', $title, $m))
						{
							$title = str_replace('^|^', '&', $title);
						}						
						
						// Horizon fixes
						//if (preg_match('/\#[A-Z]\w+(\s\w+)?\$/i', $title, $m))
						{
							$title = str_replace('#', '', $title);
							$title = str_replace('$', '', $title);
						}						
						
						$title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
						
						$title = str_replace("\n", "", $title);
					
						// assume title is English by default
						$language = 'en';
						
						$detect = true;
						
						if (count($languages_to_detect) == 1)
						{
							$language = $languages_to_detect[0];
							$detect = false;
						}							
						
						// Attempt to detect language, witgh some hacky fixes for obvious erroras
						if ($detect)
						{			
							// Detect language of title
							$ld = new Language($languages_to_detect);						
							$language = $ld->detect($title)->__toString();
							
							// double check Russian
							// https://stackoverflow.com/a/3212339/9684
							if (preg_match('/[А-Яа-яЁё]/u', $title))
							{
								$language = 'ru';
							}
															
							// double check Chinese
							if (preg_match('/\p{Han}+/u', $title))
							{
								$language = 'zh';
								
								// maybe Japanese?
								if (in_array('ja', $languages_to_detect) && !in_array('zh', $languages_to_detect)) 
								{
									$language = 'ja';
								}																
							}
							
							// double check German
							if (preg_match('/[ä|ö|ü]/iu', $title) && $language == 'en')
							{
								$language = 'de';
							}	
														
							// double check Hungarian
							if (isset($work->message->ISSN))
							{
								if (is_array($work->message->ISSN) 
								&& 
								(count(array_intersect(array('0521-4726'), $work->message->ISSN)) > 0)
								)										
								{								
									if (preg_match('/[á|é|ő|ú|ű]/iu', $title) && ($language == 'en' || $language == 'de'))
									{
										$language = 'hu';
									}	
								}	
							}													
															
							if ($language == 'en')
							{
								if (isset($work->message->ISSN))
								{
									if (is_array($work->message->ISSN) 
									&& 
									(count(array_intersect($pt_issn, $work->message->ISSN)) > 0)
									)
									
									{											
										// Portuguese doesn't seem to be detected properly
										if (preg_match('/[ç|ā|ê|á|â|ó|ô|é]/iu', $title))
										{
											$language = 'pt';
										}

										if (preg_match('/( o | dos |Notas | de | sobre | e | da |Sobre | um | ume )/iu', $title))
										{
											$language = 'pt';
										}
										
									}	
								}							
							}								
						}
					
						// Create title 
						if ($language == 'en')
						{
							// title
							$w[] = array($wikidata_properties[$k] => $language . ':' . '"' . $title . '"');

							// label
							$w[] = array('L' . $language => '"' . nice_shorten($title, $MAX_LABEL_LENGTH) . '"');
							
							// Can we deduce anything about the type of article?							
							types_from_title($w, $title);
						}
						else											
						{
							// Create title in other language, after checking for known problems
							
							if (isset($work->message->ISSN))
							{
								// Attempt to catch cases of confusing Spanish and Portuguese
								if (count(array_intersect($pt_issn, $work->message->ISSN)) > 0)
								{
									if ($language == 'es')
									{
										$language = 'pt';
									}								
								}
							}
														
							// title
							$w[] = array($wikidata_properties[$k] => $language . ':' . '"' . $title . '"');

							// label
							$w[] = array('L' . $language => '"' . nice_shorten($title, $MAX_LABEL_LENGTH) . '"');
						}
						
						// create default label for all languages
						$w[] = array('Lmul' => '"' . nice_shorten($title, $MAX_LABEL_LENGTH) . '"');
					}					
			
				}
				break;
				
			//----------------------------------------------------------------------------
			// CrossRef sometimes stores title in original language 
			// but for some journals (e.g., Darwiniana this is simply the language :()
			// this also suffers from errors in language detection :(
			case 'original-title':				
				if (0)
				{
					$title = $v;
				
					// Check if container is an array, if it is not empty take the first string
					if (is_array($v) && count($v) > 0)
					{
						$title = $v[0];
					}
				
					// by this stage we should have a string name for the container,
					// (unless record is empty array, which can happen with CrossRef)
					if (is_string($title) && trim($title) != '')
					{
						// language
						$ld = new Language($languages_to_detect);						
						$language = $ld->detect($title)->__toString();
					
						// double check
						if (preg_match('/\p{Han}+/u', $title))
						{
							$language = 'zh';
						
							// maybe Japanese?
							if (in_array('ja', $languages_to_detect) && !in_array('zh', $languages_to_detect)) 
							{
								$language = 'ja';
							}
						}
					
						// add
					
						// title
						$w[] = array($wikidata_properties['title'] => $language . ':' . '"' . $title . '"');
					
						// langauge of work (don't do this, very prone to errors)
						//$w[] = array('P407' => $language_map[$language]);	

						// label
						$w[] = array('L' . $language => '"' . nice_shorten($title, $MAX_LABEL_LENGTH) . '"');
				
					}
				}
				break;			
			
				
			//----------------------------------------------------------------------------
			case 'author':
				// For now just use author names, but will want to do lookup to see if there is an item for each person
				// in which case we would only add the item, not the name (can have one or the other)
				// Note that we can't seem to add language codes to author names, they are just dumb strings

				// Resolve every ORCID and BHL creator id up front, one query each, so the
				// per-author lookups below are served from cache (issue #21)
				$orcids = array();
				$bhl_creators = array();

				foreach ($work->message->author as $author)
				{
					if (isset($author->ORCID))
					{
						$orcids[] = $author->ORCID;
					}

					if (isset($author->BHL))
					{
						$bhl_creators[] = $author->BHL;
					}
				}

				if (count($orcids) > 0)
				{
					wikidata_items_from_orcids($orcids);
				}

				if (count($bhl_creators) > 0)
				{
					wikidata_items_from_bhl_creators($bhl_creators);
				}

				$count = 1;
				foreach ($work->message->author as $author)
				{					
					$done = false;
					
					// print_r($author);
										
					// Do we have an ORCID?
					if (!$done)
					{
						if (isset($author->ORCID))
						{
							$orcid = $author->ORCID;
							$orcid = preg_replace('/https?:\/\/orcid.org\//', '', $orcid);
							
							// echo "orcid =$orcid\n"; 
						
							$author_item = wikidata_item_from_orcid($orcid);
						
							if ($author_item != '')
							{				
								$name = csl_author_to_name($author);
								
								$qualifiers = array();
								
								$qualifiers [] = 'P1545';
								$qualifiers [] = '"' . $count . '"';
								
								// add how name is shown in metadata
								if ($name != '')
								{								
									$qualifiers [] = 'P1932';
									$qualifiers [] = '"' . addcslashes($name, '"') . '"';
								}
								
								// add affiliation data
								if (isset($author->affiliation))
								{
									foreach ($author->affiliation as $affiliation)
									{
										if (isset($affiliation->name))
										{
											// clean
											$affiliation->name = str_replace("\t", "", $affiliation->name);
											$affiliation->name = str_replace("\r", "", $affiliation->name);
											$affiliation->name = str_replace("\n", " ", $affiliation->name);
								
											$qualifiers [] = 'P6424';
											$qualifiers [] = '"' . addcslashes($affiliation->name, '"') . '"';
										}
									}						
								}								
									
								$w[] = array('P50' => $author_item . "\t" . join("\t", $qualifiers));
								$done = true;
							}						
						}						
					}
					
					// Do we have WIKISPECIES?
					if (!$done)
					{
						if (isset($author->WIKISPECIES))
						{
							$author_item = wikidata_item_from_wikispecies_author($author->WIKISPECIES);
						
							if ($author_item != '')
							{							
								$w[] = array('P50' => $author_item . "\tP1545\t\"$count\"");
								$done = true;
							}						
						}						
					}
					
					// Do we have PERSEE?
					if (!$done)
					{
						if (isset($author->PERSEE))
						{
							$author_item = wikidata_item_from_persee($author->PERSEE);
						
							if ($author_item != '')
							{							
								$w[] = array('P50' => $author_item . "\tP1545\t\"$count\"\tP1932\t\"" . $author->literal . "\"");
								$done = true;
							}						
						}						
					}

					// Do we have IDREF?
					if (!$done)
					{
						if (isset($author->IDREF))
						{
							$author_item = wikidata_item_from_idref($author->IDREF);
						
							if ($author_item != '')
							{							
								$w[] = array('P50' => $author_item . "\tP1545\t\"$count\"");
								$done = true;
							}						
						}						
					}
					
					// Do we have BHL Creator?
					// This is a bit complicated as I want to add the stated name as a qualifier,
					// and I want to credit BHL for this, so we add P50 to the list of properties that
					// lack a source, and add the source separately as BHL.
					if (!$done)
					{
						if (isset($author->BHL))
						{
							$author_item = wikidata_item_from_bhl_creator($author->BHL);
						
							if ($author_item != '')
							{	
								$name = csl_author_to_name($author);
								
								$qualifiers = array();
								
								$qualifiers [] = 'P1545';
								$qualifiers [] = '"' . $count . '"';
								
								if ($name != '')
								{								
									$qualifiers [] = 'P1932';
									$qualifiers [] = '"' . addcslashes($name, '"') . '"';
								}
																										
								if (isset($work->message->BHLPART))
								{
									$qualifiers [] = 'S248';
									$qualifiers [] = 'Q172266';
									$qualifiers [] = 'S854';
									$qualifiers [] = '"https://www.biodiversitylibrary.org/part/' . $work->message->BHLPART . '"';							
								}
									
								$w[] = array('P50' => $author_item . "\t" . join("\t", $qualifiers));

								$done = true;
								
								$properties_to_ignore[] = 'P50';
							}						
						}						
					}
										
					// Do we have ZOOBANK?
					if (!$done)
					{
						if (isset($author->ZOOBANK))
						{
							$author_item = wikidata_item_from_zoobank_author($author->ZOOBANK);
						
							if ($author_item != '')
							{							
								$w[] = array('P50' => $author_item . "\tP1545\t\"$count\"\tP1932\t\"" . $author->literal . "\"");
								$done = true;
							}						
						}						
					}
					
					// If we've reached this point we only have literals, so add these
					$ok = true;
					if (!$done)
					{						
						/*
						We may need to check for CrossRef weirdness, e.g. 
						
						doi:10.3406/linly.1963.7123
						
						[1] => stdClass Object
                        (
                            [name] => Groupe Ornithologique Lyonnais
                            [sequence] => additional
                            [affiliation] => Array
                                (
                                )

                        )						
						*/
						
						$ok = false; 
						
						$name = '';
						
						// multilingual?
						if (isset($author->multi->_key->literal))
						{
							$strings = array();
							
							// handle a bit nicer
							$authors_done = false;
							
							// for Chinese authors include English in parentheses (like Airti Library does)
							if (
								isset($author->multi->_key->literal->zh)
								&& isset($author->multi->_key->literal->en)
								) {
								
								$name = $author->multi->_key->literal->zh . '(' . $author->multi->_key->literal->en . ')';
							
								$authors_done = true;							
							}
							
							// for Japanese authors include English in parentheses (like Airti Library does)
							if (
								isset($author->multi->_key->literal->ja)
								&& isset($author->multi->_key->literal->en)
								) {
								
								$name = $author->multi->_key->literal->ja . '(' . $author->multi->_key->literal->en . ')';
							
								$authors_done = true;							
							}
																					
							if (!$authors_done)
							{
								foreach ($author->multi->_key->literal as $language => $v)
								{
									$strings[] = $v;
								}
							
								$name = join("/", $strings);	
							}
							
							$ok = true;			
						}
						else 
						{
							$name = csl_author_to_name($author);
							$ok = ($name !== "");
						}
					
						if ($ok == true)
						{
							$qualifier = "\tP1545\t\"$count\"";
					
							if (isset($author->affiliation))
							{
								foreach ($author->affiliation as $affiliation)
								{
									if (isset($affiliation->name))
									{
										// clean
										$affiliation->name = str_replace("\t", "", $affiliation->name);
										$affiliation->name = str_replace("\r", "", $affiliation->name);
										$affiliation->name = str_replace("\n", " ", $affiliation->name);
									
										$qualifier .= "\tP6424\t\"" . $affiliation->name . '"';
									}
								}						
							}
							
							// add any identifiers that we have but which we haven't matched
							if (isset($author->PERSEE))
							{	
								// Persee identifier is not allowed here
								//$qualifier .= "\tP2732\t\"" . $author->PERSEE . '"';
								
								// Maybe use URL as this qualifier is allowed?							
								// $qualifier .= "\t:P2699\t\"https://www.persee.fr/authority/" . $author->PERSEE . '"';							
							}					
					
							$w[] = array('P2093' => '"' . $name . '"' . $qualifier);
						}

					}
					if ($ok == true)
					{
						$count++;
					}
				}
				break;
		
			//----------------------------------------------------------------------------
			case 'volume':
			case 'issue':
			case 'page':
			case 'article-number':
				// clean				
				$v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');			
			
			
				$w[] = array($wikidata_properties[$k] => '"' . $v . '"');
				break;
				
			//----------------------------------------------------------------------------
			case 'alternative-id':
				// e.g. 10.5354/0717-8883.2014.31638
				if (preg_match('/Pág.\s+(?<pages>.*)/u', $v[0], $m))
				{
					$w[] = array($wikidata_properties['page'] => '"' . $m['pages'] . '"');
				}			
				break;
				
			//----------------------------------------------------------------------------
			case 'BHL':
				if (isset($work->message->BHLPART) && count($source) != 0)
				{
					$qualifiers = array();
					$qualifiers [] = 'S248';
					$qualifiers [] = 'Q172266';
					$qualifiers [] = 'S854';
					$qualifiers [] = '"https://www.biodiversitylibrary.org/part/' . $work->message->BHLPART . '"';							
	
					$w[] = array($wikidata_properties[$k] => '"' . $v . '"' . "\t" . join("\t", $qualifiers));				
				}
				else
				{
					$w[] = array($wikidata_properties[$k] => '"' . $v . '"');	
				}											
				break;

			//----------------------------------------------------------------------------
			case 'BHLPART':
				if (isset($work->message->BHLPART) && count($source) != 0)
				{
					$qualifiers = array();
					$qualifiers [] = 'S248';
					$qualifiers [] = 'Q172266';
					$qualifiers [] = 'S854';
					$qualifiers [] = '"https://www.biodiversitylibrary.org/part/' . $work->message->BHLPART . '"';							
	
					$w[] = array($wikidata_properties[$k] => '"' . $v . '"' . "\t" . join("\t", $qualifiers));
				}
				else
				{
					$w[] = array($wikidata_properties[$k] => '"' . $v . '"');	
				}											
				break;

			//----------------------------------------------------------------------------
			case 'BIOSTOR':
				// The BioStor id comes from BHL, not from whoever supplied the rest of
				// the record, so credit BHL for it the way we do for the BHL ids
				if (isset($work->message->BHLPART) && count($source) != 0)
				{
					$qualifiers = array();
					$qualifiers [] = 'S248';
					$qualifiers [] = 'Q172266';
					$qualifiers [] = 'S854';
					$qualifiers [] = '"https://www.biodiversitylibrary.org/part/' . $work->message->BHLPART . '"';

					$w[] = array($wikidata_properties[$k] => '"' . $v . '"' . "\t" . join("\t", $qualifiers));
				}
				else
				{
					$w[] = array($wikidata_properties[$k] => '"' . $v . '"');
				}
				break;

			//----------------------------------------------------------------------------
			case 'CNKI':
				$w[] = array($wikidata_properties[$k] => '"' . $v . '"');
				break;

				
			//----------------------------------------------------------------------------
			case 'DOI':
				if (isset($work->message->DOIAgency))
				{
					switch ($work->message->DOIAgency)
					{
						case 'airiti':
							$w[] = array($wikidata_properties[$k] => '"' . mb_strtoupper($v) . '"' . "\tP2378\tQ4698727");
							break;					
					
						case 'cnki':
							$w[] = array($wikidata_properties[$k] => '"' . mb_strtoupper($v) . '"' . "\tP2378\tQ12857515");
							break;					
					
						case 'crossref':
							$w[] = array($wikidata_properties[$k] => '"' . mb_strtoupper($v) . '"' . "\tP2378\tQ5188229");
							break;
										
						case 'datacite':
							$w[] = array($wikidata_properties[$k] => '"' . mb_strtoupper($v) . '"' . "\tP2378\tQ821542");
							break;

						case 'istic':
							$w[] = array($wikidata_properties[$k] => '"' . mb_strtoupper($v) . '"' . "\tP2378\tQ30262675");
							break;

						case 'jalc':
							$w[] = array($wikidata_properties[$k] => '"' . mb_strtoupper($v) . '"' . "\tP2378\tQ100319347");
							break;

						case 'medra':
							$w[] = array($wikidata_properties[$k] => '"' . mb_strtoupper($v) . '"' . "\tP2378\tQ100312513");
							break;
					
						default:
							$w[] = array($wikidata_properties[$k] => '"' . mb_strtoupper($v) . '"');
							break;						
					}
				}
				else
				{
					$w[] = array($wikidata_properties[$k] => '"' . mb_strtoupper($v) . '"');
				
					//Zenodo?
				
					if (preg_match('/10.5281\/ZENODO\.(?<id>\d+)/i', $v, $m))
					{
						$w[] = array('P4901' => '"' . $m['id'] . '"');
					}
				}

				// book chapter DOIs may include ISBN which we can use to link to parent book
				if (isset($work->message->type) && ($work->message->type == 'book-chapter') && !isset($work->message->ISBN))
				{
					$isbn_string = '';
					
					// California
					if (preg_match('/10.1525\/california\/(?<isbn>978\d+)\./', $work->message->DOI, $m))
					{
						$isbn_string = $m['isbn'];
					}

					// Springer
					if (preg_match('/10.1007\/(?<isbn>978(-\d+)+)_/', $work->message->DOI, $m))
					{
						$isbn_string = $m['isbn'];
					}
					
					if ($isbn_string != '')
					{
						$book = '';
						
						if ($book == '')
						{
							$book = wikidata_item_from_isbn10($isbn_string);
						}
						if ($book == '')
						{
							$book = wikidata_item_from_isbn13($isbn_string);
						}
						
						if ($book != '')
						{
							// part of
							$w[] = array('P361' => $book);
						}

					}
					
				}
				break;
				
			//----------------------------------------------------------------------------
			case 'JSTOR':
				$w[] = array($wikidata_properties[$k] => '"' . $v . '"');
				break;
								
			//----------------------------------------------------------------------------
			case 'HANDLE':
				$w[] = array($wikidata_properties[$k] => '"' . mb_strtoupper($v) . '"');
				break;								
				
			//----------------------------------------------------------------------------
			case 'ARCHIVE':
				$w[] = array($wikidata_properties[$k] => '"' . $v . '"');
				break;
				
			//----------------------------------------------------------------------------
			case 'PERSEE':
				$w[] = array($wikidata_properties[$k] => '"' . $v . '"');
				break;
				
				
			//----------------------------------------------------------------------------
			case 'PMID':
				$w[] = array($wikidata_properties[$k] => '"' . strtoupper($v) . '"');
				break;	

			//----------------------------------------------------------------------------
			case 'PMC':
				$w[] = array($wikidata_properties[$k] => '"' . str_replace('PMC', '', $v) . '"');
				break;	
				
			//----------------------------------------------------------------------------
			case 'SUDOC':
				$w[] = array($wikidata_properties[$k] => '"' . strtoupper($v) . '"');
				break;	
								
			//----------------------------------------------------------------------------
			case 'DIALNET':
				$w[] = array($wikidata_properties[$k] => '"' . $v . '"');
				break;
				
			//----------------------------------------------------------------------------
			case 'CINII':
				$w[] = array($wikidata_properties[$k] => '"' . $v . '"');
				break;
								
			//----------------------------------------------------------------------------
			case 'ISBN':
			
				switch ($work->message->type)
				{
					case 'book-chapter':
						$isbn_string = $v[0];
						
						$book = '';
						
						if ($book == '')
						{
							$book = wikidata_item_from_isbn10($isbn_string);
						}
						if ($book == '')
						{
							$book = wikidata_item_from_isbn13($isbn_string);
						}
												
						if ($book != '')
						{
							// part of
							$w[] = array('P361' => $book);
						}
												
						break;

					// book 
					// article
					default:
						$isbns = array();
						if (is_array($v))
						{
							$isbns = $v;
						}
						else
						{
							$isbns[] = $v;
						}
			
						foreach ($isbns as $isbn_string)
						{
							switch (strlen($isbn_string))
							{
								case 10:
									// echo " Line: " . __LINE__ . "\n";

									$w[] = array('P957' => '"' .  Isbn::convertToIsbn10($isbn_string) . '"' );			
									break;
					
								case 13:
									// echo " Line: " . __LINE__ . "\n";
				
				
									$w[] = array('P212' => '"' . Isbn::convertToIsbn13($isbn_string) . '"' );
									break;
					
								default:
									break;
			
							}
						}
					break;
				}
				
				break;								
											
				
			//----------------------------------------------------------------------------
			// BioStor CSL-JSON
			case 'bhl_pages':
				// Get first element of page array
				// https://stackoverflow.com/a/42066999/9684
				$w[] = array($wikidata_properties['BHL'] => '"' . current($v) . '"');
				break;
			
			//----------------------------------------------------------------------------
			case 'URL':
				if (is_array($v))
				{
					foreach ($v as $url)
					{
						$go = true;
						
						// Cybium
						$url = preg_replace('/\x{A0}/u', '%C2%A0', $url);
						
						if (preg_match('/https?:\/\/www.jstor.org/', $url))
						{
							// force JSTOR to be https						
							$url = preg_replace('/http:\/\/www.jstor.org/', 'https://www.jstor.org', $url);
							// For now ignore JSTOR URLs
							$go = false;
						}	
						
						if (preg_match('/[\[|<|;]/', url ))
						{
							$go = false;
						}
											
					
						if ($go)
						{
							$w[] = array($wikidata_properties[$k] => '"' . $url . '"');
						}
					}
				}
				else
				{		
					$url = $v;
					
					// Cybium
					$url = preg_replace('/\x{A0}/u', '%C2%A0', $url);
					
					$go = true;
					
					if (preg_match('/https?:\/\/www.jstor.org/', $url))
					{
						// force JSTOR to be https						
						$url = preg_replace('/http:\/\/www.jstor.org/', 'https://www.jstor.org', $url);
						// For now ignore JSTOR URLs
						$go = false;
					}	
					
					// ignore SICI based DOIs as they break quickstatements
					if (preg_match('/[\[|<|;]/', $url ))
					{
						$go = false;
					}
					
					
					//$go = false;					
				
					if ($go)
					{
						$w[] = array($wikidata_properties[$k] => '"' . $url . '"');
					}
				}
				break;
				
			//----------------------------------------------------------------------------
			case 'WIKISPECIES':
				$w[] = array('Sspecieswiki' => $v);
				break;
				
			//----------------------------------------------------------------------------
			case 'ZOOBANK':
				$w[] = array($wikidata_properties['ZOOBANK_PUBLICATION'] => '"' . $v . '"');
				break;				
				
			//----------------------------------------------------------------------------
			case 'link':
				// Some publishers such as Springer may have multiple entries for the same PDF
				// so keep track of them to avoid adding more than once
				$pdfs = array();
			
				foreach ($v as $link)
				{
					if ($link->{'content-type'} == 'application/pdf')
					{
						$go = true;
						
						// some PDFs we have to ignore as they are gone
						if (preg_match('/ci.nii.ac.jp/', $link->URL))
						{
							$go = false;
						}
						
						if (preg_match('/file:\/\//', $link->URL))
						{
							$go = false;
						}
						
						
						if (preg_match('/^S/', $link->URL))
						{
							$go = false;
						}
						
						if ($go)
						{
										
							if (in_array($link->URL, $pdfs))
							{
								// skip
							}
							else
							{
								$pdfs[] = $link->URL;
							
								$qualifier = "\tP2701\tQ42332";
					
								// do we have an archive version?
								if (isset($work->message->WAYBACK))
								{
									$wayback = $work->message->WAYBACK;
							
									if (!preg_match('/^\//', $wayback))
									{
										$wayback = '/' . $wayback;
									}
						
									$qualifier .= "\tP1065\t\"https://web.archive.org" . $wayback . '"';
								}
								$w[] = array($wikidata_properties['PDF'] => '"' . str_replace(' ', '%20', $link->URL) . '"' . $qualifier);						
							}
						}
					}
				}
				break;
								
			//----------------------------------------------------------------------------
			case 'container-title':
				$container = $v;
				
				// Check if container is an array, if it is not empty take the first string
				if (is_array($v) && count($v) > 0)
				{
					$container = $v[0];
				}
				
				// by this stage we should have a string name for the container,
				// (unless record is empty array, which can happen with CrossRef)
				if (is_string($container))
				{
				
					// OK, we need to link this to a Wikidata item

					// try via ISSN
					$journal_item = '';

					// BHL title records often name the Wikidata item outright, which saves
					// us the ISSN lookup
					if (isset($work->message->JOURNAL))
					{
						$journal_item = $work->message->JOURNAL;
					}

					if ($journal_item == '')
					{
						if (isset($work->message->ISSN))
						{
							if (is_array($work->message->ISSN))
							{
								$n = count($work->message->ISSN);
								$i = 0;
								while (($journal_item == '') && ($i < $n))
								{
									$journal_item = wikidata_item_from_issn($work->message->ISSN[$i]);
									$i++;
								}
							}
							else
							{	
								$journal_item = wikidata_item_from_issn($work->message->ISSN);
							}
						}					
							
					}	
					
					// BHL special case
					if ($journal_item == '')
					{
						if (isset($work->message->ItemID))
						{
							$journal_item = wikidata_from_bhl_item($work->message->ItemID);							
						}

					}					
									
					if ($journal_item == '')
					{
						// try to find from name
						//$journal_item = wikidata_item_from_journal_name($container, $languages_to_detect[0]);
					
						if ($container == 'The Bulletin of The Raffles Museum')
						{
							$journal_item = 'Q47083652';
						}
					
						// Abhandlungen Aus Dem Gebiete Der Naturwissenschaften Hamburg
					
						if ($container == 'Abhandlungen Aus Dem Gebiete Der Naturwissenschaften Hamburg')
						{
							$journal_item = 'Q13548385';
						}
											
						// Societas entomologica
						if ($container == 'Societas Entomologica')
						{
							$journal_item = 'Q104094462';
						}
						if ($container == 'Societas entomologica')
						{
							$journal_item = 'Q104094462';
						}
						
						// Kyoto
						if ($container == 'Memoirs of The College of Science, Kyoto Imperial University. Ser. B')
						{
							$journal_item = 'Q16606215';
						}

						// Knowia
						if ($container == 'Konowia (Vienna)')
						{
							$journal_item = 'Q47090071';
						}

						// Stettiner Entomologische Zeitung
						if ($container == 'Entomologische Zeitung Stettin')
						{
							$journal_item = 'Q9345782';
						}

					}
				
					// If we have the container in Wikidata link to it
					if ($journal_item != '')
					{
						$w[] = array('P1433' => $journal_item);
					}
				}
				break;
				
			//----------------------------------------------------------------------------
			// based on https://bitbucket.org/magnusmanske/sourcemd/src/6c998c4809df/sourcemd.php?at=master
			case 'approved': // for theses
			case 'issued':			
				$date = '';
				
				// normally we have one date, if we have two then it's a year range
				
				if (count($v->{'date-parts'}) == 1)
				{				
					$d = $v->{'date-parts'}[0];
				
					// sanity check
					if (is_numeric($d[0]))
					{
						if ( count($d) > 0 ) $year = $d[0] ;
						if ( count($d) > 1 ) $month = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$d[1] ) ;
						if ( count($d) > 2 ) $day = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$d[2] ) ;
						if ( isset($month) and isset($day) ) $date = "+$year-$month-$day"."T00:00:00Z/11";
						else if ( isset($month) ) $date = "+$year-$month-00T00:00:00Z/10";
						else if ( isset($year) ) $date = "+$year-00-00T00:00:00Z/9";
				
						$w[] = array('P577' => $date);
					
						switch ($v)
						{
							case 'approved':
								break;
						
							case 'issued':
							default:
								if (isset($year))
								{
									$description .= ' published in ' . $year;
								}
								break;				
						}
					
					
					}
				}
				
				// two dates such as a range like 1956/1957
				// assume for now that dates are years
				if (count($v->{'date-parts'}) == 2)
				{				
					$date1 = '+' . $v->{'date-parts'}[0][0] ."-00-00T00:00:00Z/9";
					$date2 = '+' . $v->{'date-parts'}[1][0] ."-00-00T00:00:00Z/9";
					$w[] = array('P577' => $date1 . "\tP1326\t" . $date2);
				}
				
				break;
				
				
			//----------------------------------------------------------------------------
			case 'reference':
				// Resolve all the cited DOIs in one batch rather than one query per
				// reference, which is what update_citation_data() already does (issue #21)
				$reference_dois = array();

				foreach ($v as $reference)
				{
					if (isset($reference->DOI))
					{
						$reference_dois[] = $reference->DOI;
					}
				}

				$reference_doi_map = array();

				if (count($reference_dois) > 0)
				{
					$reference_doi_map = wikidata_items_from_dois($reference_dois);
				}

				foreach ($v as $reference)
				{
					if (isset($reference->DOI))
					{
						// for now just see if this already exists
						$lookup_key = normalize_doi_key($reference->DOI);
						$cited = isset($reference_doi_map[$lookup_key]) ? $reference_doi_map[$lookup_key] : '';

						if ($cited != '')
						{
							$w[] = array('P2860' => $cited);
						}
					}
				}
				break;
				

/*				
funder: [
{
DOI: "10.13039/501100001659",
name: "Deutsche Forschungsgemeinschaft",
doi-asserted-by: "publisher",
award: [
"PA 1818/3-1",
"HU 1561/1-1, 1-2"
]
},
{
name: "European Union Improving Human Potential program SYNTHESYS",
award: [
"GB-TAF-3410",
"GB-TAF-5177"
]
}
],
*/

			//----------------------------------------------------------------------------
			case 'funder':
				foreach ($v as $funder)
				{
					//print_r($funder);
					if (isset($funder->DOI))
					{
						$funder_qid = wikidata_funder_from_doi($funder->DOI);
						if ($funder_qid != '')
						{
							$w[] = array('P859' => $funder_qid);
						}
					}				
				}
				break;
				
			//----------------------------------------------------------------------------
			// Datacite
			case 'copyright':
				$license_item = '';
				switch ($v)
				{
					case 'Creative Commons BY-NC-ND 3.0 FR':
						$license_item = 'Q19125045';
						break;
						
					default:
						break;
				}
				
				if ($license_item != '')
				{
					$w[] = array('P275' => $license_item);
				}					
				break;
				
				
			//----------------------------------------------------------------------------
			case 'publisher':
				$publisher_item = '';
				switch ($v)
				{
					case 'Barcode of Life Data Systems':
						$publisher_item = 'Q16934719';
						break;
						
					default:
						break;
				}
				
				if ($publisher_item != '')
				{
					$w[] = array('P123' => $publisher_item);
				}					
						
				break;
				
			//----------------------------------------------------------------------------
			case 'license':
				if (is_array($v))
				{
					$licenses = array();
					
					foreach ($v as $license)
					{
						//print_r($license);
						
						if (isset($license->URL))
						{				
							// map to Wikidata
							switch ($license->URL)
							{
							  
							  
								case 'https://creativecommons.org/licenses/by/4.0/':
								case 'https://creativecommons.org/licenses/by/4.0':
								case 'http://creativecommons.org/licenses/by/4.0/':
									// CC-BY 4.0
									$licenses[] = 'Q20007257';
									break;
							  
								case 'https://creativecommons.org/licenses/by-nd/4.0/':						
									// CC-BY-ND 4.0 
									$licenses[] = 'Q36795408';
									break;
							
								case 'http://creativecommons.org/licenses/by-nc/3.0/':						
								case 'http://creativecommons.org/licenses/by-nc/3.0/nl/':						
									// CC-BY-NC 
									$licenses[] = 'Q18810331';					
									break;
								
								case 'https://creativecommons.org/licenses/by-nc/4.0':
									// CC-BY-NC  4.0
									$licenses[] = 'Q34179348';
									break;
							
								case 'http://creativecommons.org/licenses/by-sa/3.0/nl/':
									// CC-BY-SA 
									$licenses[] = 'Q14946043';												
									break;
								
								case 'http://creativecommons.org/licenses/by-sa/4.0':
									// CC-BY-SA 
									$licenses[] = 'Q18199165';												
									break;
								
								case 'https://creativecommons.org/licenses/by-nc-sa':							
									// CC-BY-NC-SA unknown version
									$licenses[] = 'Q6998997';												
									break;
															
								case 'https://creativecommons.org/licenses/by-nc-nd/4.0/':
									// CC-BY-NC-ND 
									$licenses[] = 'Q24082749';
									break;
							
								case 'https://creativecommons.org/licenses/by-nc-nd/1.0/':
									// CC-BY-NC-ND 
									$licenses[] = 'Q47008926';
									break;
								
								case 'http://creativecommons.org/licenses/by-nc-nd/3.0':
								case 'http://creativecommons.org/licenses/by-nc-nd/3.0/':
									// CC-BY-NC-ND 3.0
									$licenses[] = 'Q19125045';
									break;								
								
								case 'http://creativecommons.org/licenses/by-nc-nd/4.0/':
								case 'https://creativecommons.org/licenses/by-nc-nd/4.0/':
									// CC-BY-NC-ND 4.0
									$licenses[] = 'Q24082749';
									break;
								
								case 'http://creativecommons.org/licenses/by-nc-sa/3.0/':
								case 'http://creativecommons.org/licenses/by-nc-sa/3.0':
									// CC-BY-NC-SA 3.0
									$licenses[] = 'Q15643954';
									break;
								
								case 'https://creativecommons.org/licenses/by-nc-sa/4.0/';
								case 'http://creativecommons.org/licenses/by-nc-sa/4.0/';
									// CC-BY-NC-SA 4.0
									$licenses[] = 'Q42553662';
									break;
																			
								default:
									break;
							}
							
						}
					}
					
					// Add unique licenses
					if (count($licenses) > 0)
					{
						$licenses = array_unique($licenses);
						$w[] = array('P6216' => 'Q50423863'); // copyright
						
						foreach ($licenses as $license_item)
						{
							$w[] = array('P275' => $license_item);
						}							
					}
					
				}
				break;
				
			//----------------------------------------------------------------------------
			case 'abstract':
				if (0)
				{
					// Handle multiple languages
					$done = false;
				
					if (isset($work->message->multi))
					{
						if (isset($work->message->multi->_key->abstract))
						{					
							foreach ($work->message->multi->_key->abstract as $language => $text)
							{
								$text = preg_replace('/^<jats:p>/u', '', $text);
								$text = nice_strip_tags($text);
								$text = preg_replace('/^(SUMMARY|Abstract|ABSTRACT|INTRODUCTION)/u', '', $text);
						
				
							
								$sentences = '';
							
								switch ($language)
								{
									case 'zh':
										$sentences = preg_split('/。/u', $text);
										break;							
							
									case 'en':
									default:
										// sentence split (assumes English-style text)
										// see https://stackoverflow.com/a/16377765/9684 for some ideas
										$sentences = preg_split('/(?<=[a-z\)])[.?!](?=\s+[A-Z])/u', $text);
										break;
								}
							
								
								if (count($sentences) != 0)
								{
									$first_line = $sentences[0] . '.';	
									$first_line = preg_replace('/\n/u', ' ', $first_line);
									$first_line = preg_replace('/\s\s+/u', ' ', $first_line);								
									$first_line = nice_shorten($first_line);
				
									$w[] = array($wikidata_properties[$k] => $language . ':' . '"' . $first_line . '"');
								}
							}					
							$done = true;
						}					
					}
			
					if (!$done)
					{			
						// one language only
						$text = $v;
					
						// for now just single language 9to do: multilingual)
				
						// clean
						$text = str_replace('<jats:p>-</jats:p>', '', $text);
						$text = preg_replace('/^<jats:p>/u', '', $text);
						$text = str_replace('..', '', $text);
					
					
						$text = nice_strip_tags($text);
					
						$text = preg_replace('/^(SUMMARY|Abstract|ABSTRACT|INTRODUCTION)\s*/u', '', $text);
					
					
						if ($text != '')
						{
				
							// sentence split (assumes English-style text)
							// see https://stackoverflow.com/a/16377765/9684 for some ideas
							$sentences = preg_split('/(?<=[a-z\)])[.?!](?=\s+[A-Z])/u', $text);
								
							if (count($sentences) != 0)
							{
								$first_line = $sentences[0] . '.';
								$first_line = preg_replace('/\n/u', ' ', $first_line);
								$first_line = preg_replace('/\s\s+/u', ' ', $first_line);								
						
								$first_line = nice_shorten($first_line);
				
								// Detect language of first_line
								$ld = new Language($languages_to_detect);						
								$language = $ld->detect($first_line)->__toString();
						
								// We don't seem to detect Portguese reliably
								
								if (isset($work->message->ISSN) && is_array($work->message->ISSN) && count(array_intersect($work->message->ISSN, $pt_issn)) > 0)
								{
									if ($language == 'es')
									{
										$language = 'pt';
									}								
								}
						

								$w[] = array($wikidata_properties[$k] => $language . ':' . '"' . $first_line . '"');
							}
						}
					}
				}
				break;
				
	
			//----------------------------------------------------------------------------
			default:
				break;
		}
	}
	
	
	// description can be problematic if we have multiple articles with the same title, quickstatement flags an error
	if ($description != '')
	{
		//$w[] = array('Den' => '"' . $description . '"');	
	}	
	
	// assume create
	if ($item == 'LAST')
	{
		$quickstatements .= "CREATE\n";
	}	
	
	foreach ($w as $statement)
	{
		foreach ($statement as $property => $value)
		{
			$row = array();
			$row[] = $item;
			$row[] = $property;
			$row[] = $value;
		
			$quickstatements .= join("\t", $row);			
							
			if (count($source) > 0 && !preg_match('/^[D|L]/', $property) && !in_array($property, $properties_to_ignore))
			{
				$quickstatements .= "\t" . join("\t", $source);
			}
			
			$quickstatements .= "\n";
			
		}
	}
	
	return $quickstatements;

	
}

//----------------------------------------------------------------------------------------
// OpenURL-style metadata lookup.
//
// Scholarly articles and the journals that contain them now live in different Wikidata
// query endpoints: the article is only in query-scholarly, the journal (and hence its
// ISSN and label) is only in query. A single query that joins ?work -> ?container -> ?issn
// spans both graphs and so matches nothing on either endpoint, which silently disabled
// every metadata-based check. Resolve the container to an item first, then look the work
// up against that item in the scholarly endpoint.
function wikidata_item_from_openurl_container($container_item, $volume, $spage, $year)
{
	$item = '';
	
	if ($container_item == '' || $volume == '' || $spage == '' || $year == '')
	{
		return $item;
	}
	
	$sparql = 'SELECT * WHERE 
{ 
  VALUES ?container { wd:' . $container_item . ' } .
  VALUES ?volume {"' . addcslashes($volume, '"') . '" } .
  VALUES ?firstpage {"^' . $spage . '([^0-9]|$)" } .
  VALUES ?year {"' . $year . '" } .
  
  ?work wdt:P1433 ?container .
  ?work wdt:P478 ?volume .
  ?work wdt:P304 ?pages .
  ?work wdt:P577 ?date .
  FILTER regex(?pages,?firstpage,"i")
  FILTER (STR(year(?date)) = ?year)
}';
	
	// echo $sparql . "\n";
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
		
	if ($json != '')
	{
		$obj = json_decode($json);
		
		//print_r($obj);
		
		if (isset($obj->results->bindings))
		{
			if (count($obj->results->bindings) != 0)	
			{
				$item = $obj->results->bindings[0]->work->value;
				$item = preg_replace('/https?:\/\/www.wikidata.org\/entity\//', '', $item);
			}
		}
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// OpenURL lookup using ISSN, volume, spage
function wikidata_item_from_openurl_issn($issn, $volume, $spage, $year)
{
	$item = '';
	
	$container_item = wikidata_item_from_issn($issn);
	
	if ($container_item != '')
	{
		$item = wikidata_item_from_openurl_container($container_item, $volume, $spage, $year);
	}
	
	return $item;
}

//----------------------------------------------------------------------------------------
// OpenURL lookup using journal name, volume, spage
function wikidata_item_from_openurl_journal($journal, $volume, $spage, $year, $language = 'en')
{
	$item = '';
	
	$container_item = wikidata_item_from_journal_name($journal, $language);
	
	if ($container_item != '')
	{
		$item = wikidata_item_from_openurl_container($container_item, $volume, $spage, $year);
	}
	
	return $item;
}



//----------------------------------------------------------------------------------------
// Try to locate an item using any identifier or metadata that we have
function wikidata_find_from_anything ($work)
{
	// Do we have this already in wikidata?
	$item = '';
	
	// DOI
	if (isset($work->message->DOI))
	{
		$item = wikidata_item_from_doi($work->message->DOI);
	}

	// JSTOR
	if ($item == '')
	{
		if (isset($work->message->JSTOR))
		{
			$item = wikidata_item_from_jstor($work->message->JSTOR);
		
		}
	}	

	// BioStor
	if ($item == '')
	{
		if (isset($work->message->BIOSTOR))
		{
			$item = wikidata_item_from_biostor($work->message->BIOSTOR);
		}
	}	
	
	// Handle
	if ($item == '')
	{
		if (isset($work->HANDLE))
		{
			$item = wikidata_item_from_handle($work->HANDLE);
		}
	}		
	
	// PMID
	if ($item == '')
	{
		if (isset($work->message->PMID))
		{
			$item = wikidata_item_from_pmid($work->message->PMID);
		}
	}	
	
	// ISBN	
	if ($item == '')
	{
		if (strlen($work->message->ISBN) == 10)
		{
			$item = wikidata_item_from_isbn10($work->message->ISBN);
		}
		if (strlen($work->message->ISBN) == 13)
		{
			$item = wikidata_item_from_isbn13($work->message->ISBN);
		}		
	}	

	// PDF
	if ($item == '')
	{
		if (isset($work->message->link))
		{
			foreach ($work->message->link as $link)
			{
				if ($link->{'content-type'} == 'application/pdf')
				{
					$item = wikidata_item_from_pdf($link->URL);
				}
			}
		}
	}	
	
	// OpenURL
	if ($item == '')
	{
		$terms = array();
				
		$issn = $volume = $spage = '';
		
		if (isset($work->message->ISSN))
		{
			$terms[] = $work->message->ISSN;
		}		
		
		if (isset($work->message->volume))
		{
			$terms[] = $work->message->volume;
		}

		if (isset($work->message->{'page-first'}))
		{
			$terms[] = $work->message->{'page-first'};
		}
				
		if (isset($work->message->{'issued'}))
		{
			$terms[] = $work->message->{'issued'}->{'date-parts'}[0][0];
		}
			
		if (count($terms) == 4)
		{
			foreach ($terms[0] as $issn)
			{
				$hit = wikidata_item_from_openurl_issn($issn, $terms[1], $terms[2], $terms[3]);
				if ($hit <> '')
				{
					$item = $hit;
				}
			}
		}

	}	
	
	return $item;	

}

//----------------------------------------------------------------------------------------
// Update based on subset of data, e.g. citations
// Convert a csl json object to Wikidata quickstatments
function update_citation_data($work, $item, $source = array())
{
	$quickstatements = '';
	
	$w = array();
	
	$reference_doi_map = array();
	
	if (isset($work->message->reference))
	{
		$reference_dois = array();
		
		foreach ($work->message->reference as $reference)
		{
			if (isset($reference->DOI))
			{
				$reference_dois[] = $reference->DOI;
			}
		}
		
		if (count($reference_dois) > 0)
		{
			$reference_doi_map = wikidata_items_from_dois($reference_dois);
		}
	}
		
	foreach ($work->message as $k => $v)
	{

		switch ($k)
		{
				
			case 'reference':
				foreach ($v as $reference)
				{
					
				if (isset($reference->DOI))
				{
					$cited = '';
					$lookup_key = mb_strtoupper(trim($reference->DOI));
					
					if ($lookup_key != '' && isset($reference_doi_map[$lookup_key]))
					{
						$cited = $reference_doi_map[$lookup_key];
					}
					else
					{
						$lookup = wikidata_items_from_dois(array($reference->DOI));
						if ($lookup_key != '' && isset($lookup[$lookup_key]))
						{
							$cited = $lookup[$lookup_key];
						}
					}
					
					if ($cited != '')
					{
						$w[] = array('P2860' => $cited);
					}					
				}
				else
				{

						// lets try metadata-based search (OpenURL)
						$parts = array();
	
						if (isset($reference->ISSN))
						{
							$parts[] = str_replace("http://id.crossref.org/issn/", '', $reference->ISSN);

							if (isset($reference->volume))
							{
								$parts[] = $reference->volume;
							}
							if (isset($reference->{'first-page'}))
							{
								$parts[] = $reference->{'first-page'};
							}
							if (isset($reference->year))
							{
								$parts[] = $reference->year;
							}	
	
							if (count($parts == 4))
							{
								$cited = wikidata_item_from_openurl_issn($parts[0], $parts[1], $parts[2], $parts[3]);
								
								if ($cited != '')
								{								
									$w[] = array('P2860' => $cited);
								}	
							}						
						}
					}
					
				}
				break;
	
			default:
				break;
		}
	}
	
	
	foreach ($w as $statement)
	{
		foreach ($statement as $property => $value)
		{
			$row = array();
			$row[] = $item;
			$row[] = $property;
			$row[] = $value;
		
			$quickstatements .= join("\t", $row);
			
			// labels don't get references 
			$properties_to_ignore = array();
			
			$properties_to_ignore = array(
				'P724',
				'P953',
				'P407', // language of work is almost never set by the source
				'P1922',
			); // e.g., when adding PDFs or IA to records from JSTOR
							
			if (count($source) > 0 && !preg_match('/^[D|L]/', $property) && !in_array($property, $properties_to_ignore))
			{
				$quickstatements .= "\t" . join("\t", $source);
			}
			
			$quickstatements .= "\n";
			
		}
	}
	
	
	return $quickstatements;

	
}

//----------------------------------------------------------------------------------------
// Which of the identifiers we hold does this item already have?
//
// Returns an array keyed by property, each entry an array of the values Wikidata has.
function wikidata_identifiers_for_item($item, $properties)
{
	$have = array();
	
	if ($item == '' || count($properties) == 0)
	{
		return $have;
	}
	
	$values = array();
	
	foreach ($properties as $property)
	{
		$values[] = 'wdt:' . $property;
		$have[$property] = array();
	}
	
	$sparql = 'SELECT ?p ?v WHERE {';
	$sparql .= ' VALUES ?p { ' . join(' ', $values) . ' }';
	$sparql .= ' wd:' . $item . ' ?p ?v .';
	$sparql .= ' }';
	
	// echo $sparql . "\n";
	
	$url = 'https://query-scholarly.wikidata.org/bigdata/namespace/wdq/sparql?query=' . urlencode($sparql);
	$json = get($url, '', 'application/json');
	
	if ($json != '')
	{
		$obj = json_decode($json);
		
		if (isset($obj->results->bindings))
		{
			foreach ($obj->results->bindings as $binding)
			{
				$property = preg_replace('/^.*\/(?<p>P\d+)$/', '$1', $binding->p->value);
				
				if (isset($have[$property]))
				{
					$have[$property][] = $binding->v->value;
				}
			}
		}
	}
	
	return $have;
}

//----------------------------------------------------------------------------------------
// The work is already in Wikidata, but may be missing some of the identifiers we have for
// it (e.g. an item created from its DOI that has never been given a BioStor id). Generate
// Quickstatements for just those, rather than re-asserting the whole record.
function wikidata_missing_identifier_statements($item, $work)
{
	$quickstatements = '';
	
	if ($item == '' || !isset($work->message))
	{
		return $quickstatements;
	}
	
	$message = $work->message;
	
	// Identifiers we might be able to contribute, in the order we want to emit them
	$candidates = array();
	
	if (isset($message->BIOSTOR))
	{
		$candidates['P5315'] = (string)$message->BIOSTOR;
	}
	
	if (isset($message->BHLPART))
	{
		$candidates['P6535'] = (string)$message->BHLPART;
	}
	
	if (isset($message->BHL))
	{
		$candidates['P687'] = (string)$message->BHL;
	}
	
	if (isset($message->DOI))
	{
		$candidates['P356'] = mb_strtoupper($message->DOI);
	}
	
	if (isset($message->JSTOR))
	{
		$candidates['P888'] = (string)$message->JSTOR;
	}
	
	if (count($candidates) == 0)
	{
		return $quickstatements;
	}
	
	$have = wikidata_identifiers_for_item($item, array_keys($candidates));
	
	// Everything here came from the BHL API, so credit BHL as the source
	$source = array();
	
	if (isset($message->BHLPART))
	{
		$source[] = 'S248';
		$source[] = 'Q172266'; // Biodiversity Heritage Library
		$source[] = 'S854';
		$source[] = '"https://www.biodiversitylibrary.org/part/' . $message->BHLPART . '"';
	}
	
	foreach ($candidates as $property => $value)
	{
		$existing = isset($have[$property]) ? $have[$property] : array();
		
		$found = false;
		
		foreach ($existing as $current)
		{
			if (mb_strtoupper($current) == mb_strtoupper($value))
			{
				$found = true;
			}
		}
		
		if (!$found)
		{
			$statement = $item . "\t" . $property . "\t" . '"' . addcslashes($value, '"') . '"';
			
			if (count($source) > 0)
			{
				$statement .= "\t" . join("\t", $source);
			}
			
			$quickstatements .= $statement . "\n";
		}
	}
	
	return $quickstatements;
}

?>
