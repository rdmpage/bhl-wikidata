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
function get_part_from_bhl_part($id)
{
	$part = null;
	
	$parameters = array(
		'op' 		=> 'GetPartMetadata',
		'partid'	=> $id,
		'apikey'	=> getenv('BHL_API_KEY'),
		'format'	=> 'json'
	);
	
	$url = 'https://www.biodiversitylibrary.org/api2/httpquery.ashx?' . http_build_query($parameters);
	
	$json = get($url);
	
	$obj = json_decode($json);
	
	// print_r($obj);
	
	if ($obj && isset($obj->Result))
	{
		$part = $obj->Result;
	}
	
	return $part;
}


//----------------------------------------------------------------------------------------
function add_from_doi($doi, $update = false)
{
	$result = null;
	
	$source = array();
	
	$check = true; // just to be safe
	//$check = false; // do you feel lucky?
	
	$detect_languages = array('en');
	$detect_languages = array('en', 'fr', 'de', 'pt', 'es', 'ja', 'zh', 'ru', 'ar', 'pa', 'hi');	
	
	$doi = strtolower($doi);
	
	$go = true;
	
	$item = wikidata_item_from_doi($doi);
	
	if ($item != '')
	{
		if (!$update)
		{
			$go = false;
			$result = $item;			
		}
	}
	
	if ($go)
	{
		$work = get_work($doi);
		
		//print_r($work);
		
		if ($work)
		{
			// anything extra?
			
			// If this is a BHL DOI for a part we attempt to match authors to BHL ids
			if (preg_match('/10\.5962\/(bhl\.part|p)\.(?<part>\d+)/i', $doi, $m))
			{
				$part = get_part_from_bhl_part($m['part']);
				
				if (0)
				{
					echo '<pre>';
					print_r($part);
					echo '</pre>';
				}
				
				if ($part)
				{
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
									
				}
			}
		}
		
		
		if ($work)
		{
			if (1) // 1 if we want to add references for each statement
			{
				$agency = doi_to_agency($doi);
	
				switch ($agency)
				{
					case 'Crossref':	
						$url = 'https://api.crossref.org/v1/works/' . $doi;
												
						$source[] = 'S248';
						$source[] = 'Q5188229'; // CrossRef
							
						$source[] = 'S854';
						$source[] = '"' . $url . '"';
						break;
	
					case 'JaLC':
						$url = 'https://doi.org/' . $doi;	
						
						$source[] = 'S248';
						$source[] = 'Q100319347'; // JaLC
							
						$source[] = 'S854';
						$source[] = '"' . $url . '"';
						break;
							
					default:
						break;	
				}
			}
			else
			{
				$source = array();
			}		
		}
		
		$q = csljson_to_wikidata(
			$work, 
			$check,  // check if already exists
			$update, // true to update an existing record, false to skip an existing record
			$detect_languages,
			$source,
			true // create English language label
			);
			
		$result = $q;
	}
	
	return $result;
}

//----------------------------------------------------------------------------------------

if (isset($_GET['ids']) && trim($_GET['ids']) != "")
{
	// process
	$results = array();
	
	$ids = explode("\n", trim($_GET['ids']));
	
	//echo '<pre>';
	//print_r($ids);
	
	foreach ($ids as $id)
	{
		$id = trim($id);
	
		$id_type = 'unknown';
		
		if ($id_type == 'unknown')
		{
			if (preg_match('/^10\.[0-9]{4,}(?:\.[0-9]+)*(?:\/|%2F)(?:(?![\"&\'])\S)+/', $id))
			{
				$id_type = 'doi';
			}
		}
		
		switch ($id_type)
		{
			case 'doi':
				$results[$id] = add_from_doi($id);
				break;
				
			default:
				$results[$id] = null;
				break;
		}
		
	}
	
	//print_r($results);
	
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
			
	</style>	
</head>
<body>
<h1>
	<a href=".">[Home]</a>
</h1>

<p>You can create a new item in QuickStatements:</p>


<form action='https://tools.wmflabs.org/quickstatements/api.php' method='post' target='_blank'>
<input type='hidden' name='action' value='import' />
<input type='hidden' name='format' value='v1' />
<input type='hidden' name='temporary' value='1' />
<input type='hidden' name='openpage' value='1' />
<textarea style="padding:1em;font-size:1em;box-sizing: border-box;width:100%;" name="data" rows="20" >
<?php

$have_already = array();
$bad_identifier = array();

foreach ($results as $id => $result)
{
	if ($result)
	{
		if (preg_match('/^CREATE/', $result))
		{
			echo $result . "\n";
		}
		else
		{
			$have_already[$id] = $result;
		}
	}
	else
	{
		$bad_identifier[] = $id;
	}
}

?>
</textarea>
    <br />
    <button type="submit">Open in Quickstatements</button>
</form>

<?php

	if (count($have_already) > 0)
	{
		echo '<h2>' . count($have_already) . ' DOI(s) already exist in Wikidata</h2>';
		
		echo '<table>';
		echo '<tr><th>DOI</th><th>Wikidata item</th></tr>';
		foreach ($have_already as $id => $qid)
		{
			echo '<tr>';
			echo '<td>' . $id . '</td>';
			echo '<td>' . '<a href="https://www.wikidata.org/wiki/' . $qid . '" target="_blank">' . $qid . '</a>' . '</td>';
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
			echo '<li>' . $id . '</li>';
		}
		echo '</ul>';
	}
	


?>


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
	</style>	
</head>
<body>
<h1>
	<a href=".">BHL to Wikidata</a>
</h1>

<p>A tool by Rod Page, code on <a href="https://github.com/rdmpage/bhl-wikidata" target="_blank">GitHub</a></p>

<p>This tool is inspired by <a href="https://sourcemd.toolforge.org/index_old.php">SourceMD</a> and works in much the same way. 
Enter one or more DOIs, one per line. The tool checks whether the DOIs already exist in Wikidata,
if not it will create Quickstatements for them so you can add them yourself.</p>

<p>There is also another tool, <a href="cites.php">Cites Works</a> which adds citation links to works with DOIs.</p>

<form method="get">
	<textarea style="font-size:1em;box-sizing: border-box;width:100%;" id="ids"  name="ids" rows="10" placeholder="Enter DOIs here, one per line" ></textarea>
    <br />
    <!-- <button type="submit" name="check">Check</button> -->
    <button type="submit" name="add">Check and add</button>
</form>


</body>
</html>

<?php
}
?>
