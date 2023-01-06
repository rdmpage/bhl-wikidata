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
		
		$results[$id] = null; // default is we have nothing
		
		switch ($id_type)
		{
			case 'doi':
				
				// Do we have this already in wikidata?
				$item = wikidata_item_from_doi($id);

				// If not found then retrun	
				if ($item == '')
				{
					$results[$id] = null;
				}
				else
				{
					$work = get_work($id);
					
					if ($work)
					{
			
						$source = array();
			
						$source[] = 'S248';
						$source[] = 'Q5188229';
							
						if (preg_match('/[\[|<|;]/', $id))
						{
							// Some DOIs (such as BioOne SICIs) break Quickstatements
							// so we don't add these as the source
						}
						else
						{				
							// DOI seems fine, so be explict about source of data
							$source[] = 'S854';
							$source[] = '"https://api.crossref.org/v1/works/' . $id . '"';
						}
			
						$results[$id] = update_citation_data($work, $item, $source);
					}
				}
				break;
				
			default:
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
<h2>
	<a href="cites.php">[Cites works]</a>
</h2>

<p>You can create a new item in QuickStatements:</p>


<form action='https://tools.wmflabs.org/quickstatements/api.php' method='post' target='_blank'>
<input type='hidden' name='action' value='import' />
<input type='hidden' name='format' value='v1' />
<input type='hidden' name='temporary' value='1' />
<input type='hidden' name='openpage' value='1' />
<textarea style="padding:1em;font-size:1em;box-sizing: border-box;width:100%;" name="data" rows="20" >
<?php


$bad_identifier = array();

foreach ($results as $id => $result)
{
	if ($result)
	{
		echo $result . "\n";
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
	
	if (count($bad_identifier) > 0)
	{
		echo '<h2>No data for:</h2>';
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
	<title>Wikidata Cites Work(s)</title>
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
<h2>
	<a href="cites.php">Wikidata Cites Work(s)</a>
</h2>

<p>A tool by Rod Page, code on <a href="https://github.com/rdmpage/bhl-wikidata" target="_blank">GitHub</a></p>

<p>This tool takes one or more DOIs and creates "cites work" entries for each item. Note that this tool only works 
if (a) there is a Wikidata item for the DOI, and (b) CrossRef has citation links for that DOI, and those works cited 
also have DOIs and have items in Wikidata.</p>

<p>The output from this tool is a list of Quickstatements that you can add to Wikidata.</p>

<form method="get" action="cites.php">
	<textarea style="font-size:1em;box-sizing: border-box;width:100%;" id="ids"  name="ids" rows="10" placeholder="Enter DOIs here, one per line" ></textarea>
    <br />
    <!-- <button type="submit" name="check">Check</button> -->
    <button type="submit" name="add">Get cited works</button>
</form>


</body>
</html>

<?php
}
?>
