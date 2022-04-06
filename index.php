<?php

require_once(dirname(__FILE__) . '/wikidata.php');

//----------------------------------------------------------------------------------------
function add_from_doi($doi, $update = false)
{
	$result = null;
	
	$check = true; // just to be safe
	$check = false; // do you feel lucky?
	
	$detect_languages = array('en');
	$detect_languages = array('en', 'fr', 'de', 'pt', 'es', 'ja', 'zh', 'ru');	
	
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
			$source = array();
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
	
	$ids = explode("\n", $_GET['ids']);
	
	//echo '<pre>';
	//print_r($ids);
	
	foreach ($ids as $id)
	{
		$id = trim($id);
	
		$id_type = 'unknown';
		
		if ($id_type == 'unknown')
		{
			if (preg_match('/^10\.\d+/', $id))
			{
				$id_type = 'doi';
			}
		}
		
		switch ($id_type)
		{
			case 'doi':
			default:
				$results[$id] = add_from_doi($id);
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
			padding:20px;
		}
		
	button {
		font-size:1em;
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

<p>You can create a new item, or update an existing one, in QuickStatements:</p>


<form action='https://tools.wmflabs.org/quickstatements/api.php' method='post' target='_blank'>
<input type='hidden' name='action' value='import' />
<input type='hidden' name='format' value='v1' />
<input type='hidden' name='temporary' value='1' />
<input type='hidden' name='openpage' value='1' />
<textarea style="padding:1em;font-size:1em;box-sizing: border-box;width:100%;" name="data" rows="20" >
<?php

$have_already = array();

foreach ($results as $id => $result)
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
			padding:20px;
		}
		
	button {
		font-size:1em;
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

<p>This tool is inspired by <a href="https://sourcemd.toolforge.org/index_old.php">SourceMD</a> and works in much the same way. 
Enter one or more DOIs, one per line. The tool checks whether the DOIs already exist in Wikidata,
if not it will create Quickstatements for them so you can add them yourself.</p>

<form method="get">
	<textarea style="font-size:1em;box-sizing: border-box;width:100%;" id="ids"  name="ids" rows="10" ></textarea>
    <br />
    <!-- <button type="submit" name="check">Check</button> -->
    <button type="submit" name="add">Check and add</button>
</form>


</body>
</html>

<?php
}
?>