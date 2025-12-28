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
require_once(dirname(__FILE__) . '/lib/Queue.php');

//----------------------------------------------------------------------------------------
// Get BHL part from DOI (typically an external id)
function get_part_from_doi($doi)
{
	$part = null;
	
	$parameters = array(
		'op' 		=> 'GetPartByIdentifier',
		'type'		=> 'doi',
		'value'		=> strtolower($doi),
		'apikey'	=> getenv('BHL_API_KEY'),
		'format'	=> 'json'
	);
	
	$url = 'https://www.biodiversitylibrary.org/api2/httpquery.ashx?' . http_build_query($parameters);
	
	$json = get($url);
	
	$obj = json_decode($json);
	
	if ($obj && isset($obj->Result) && count($obj->Result) > 0)
	{
		$part = $obj->Result[0];
	}
	
	return $part;
}

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
			
			// Is a BHL part?
			$part = null;
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
			
			if ($part)
			{
				
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
						
					case 'DataCite':
						$url = 'https://doi.org/' . $doi;	
						
						$source[] = 'S248';
						$source[] = 'Q821542'; // DataCite
							
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
	// Parse identifiers and detect types
	$items = array();

	$ids = explode("\n", trim($_GET['ids']));

	foreach ($ids as $id)
	{
		$id = trim($id);

		if ($id === '') {
			continue;
		}

		$id_type = 'unknown';

		// Detect identifier type
		if (preg_match('/^10\.[0-9]{4,}(?:\.[0-9]+)*(?:\/|%2F)(?:(?![\"&\'])\S)+/', $id))
		{
			$id_type = 'doi';
		}

		// Only add known identifier types
		if ($id_type !== 'unknown')
		{
			$items[] = array(
				'pid' => $id,
				'pid_type' => $id_type
			);
		}
	}

	// Create batch in queue
	if (count($items) > 0)
	{
		$queue = new Queue();
		$batch_id = $queue->createBatch($items);

		// Try to spawn worker in background
		$worker_script = dirname(__FILE__) . '/worker.php';
		if (file_exists($worker_script)) {
			// Attempt to spawn worker (this may fail depending on server config)
			@exec('php ' . escapeshellarg($worker_script) . ' > /dev/null 2>&1 &');
		}

		// Redirect to progress page
		header('Location: ?batch_id=' . urlencode($batch_id));
		exit;
	}
	
}
else if (isset($_GET['batch_id']))
{
	// Show progress page
	$batch_id = $_GET['batch_id'];

?>

<html>
<head>
	<meta charset="utf-8" />
	<title>BHL Wikidata - Processing</title>
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

		.progress-bar {
			width:100%;
			height:30px;
			background-color:#f0f0f0;
			border-radius:4px;
			overflow:hidden;
			margin:20px 0;
		}

		.progress-fill {
			height:100%;
			background-color:#4CAF50;
			transition: width 0.3s ease;
			display:flex;
			align-items:center;
			justify-content:center;
			color:white;
			font-weight:bold;
		}

		.status-text {
			margin:10px 0;
			font-size:1.1em;
		}

		#results-section {
			display:none;
		}

		table {
			border-collapse: collapse;
			width:100%;
			margin:20px 0;
		}

		table th, table td {
			border:1px solid #ddd;
			padding:8px;
			text-align:left;
		}

		table th {
			background-color:#f0f0f0;
		}
	</style>
</head>
<body>
<h1>
	<a href=".">[Home]</a>
</h1>

<h2>Processing Identifiers</h2>

<div class="status-text" id="status-text">Initializing...</div>

<div class="progress-bar">
	<div class="progress-fill" id="progress-fill" style="width:0%">0%</div>
</div>

<div id="results-section">
	<h3>Results</h3>

	<div id="quickstatements-section" style="display:none;">
		<p>You can create new items in QuickStatements:</p>
		<form action='https://tools.wmflabs.org/quickstatements/api.php' method='post' target='_blank' id="qs-form">
			<input type='hidden' name='action' value='import' />
			<input type='hidden' name='format' value='v1' />
			<input type='hidden' name='temporary' value='1' />
			<input type='hidden' name='openpage' value='1' />
			<textarea style="padding:1em;font-size:1em;box-sizing:border-box;width:100%;" name="data" rows="20" id="qs-data"></textarea>
			<br />
			<button type="submit">Open in Quickstatements</button>
		</form>
	</div>

	<div id="existing-section" style="display:none;">
		<h3>Already in Wikidata</h3>
		<div id="existing-list"></div>
	</div>

	<div id="errors-section" style="display:none;">
		<h3>Errors</h3>
		<div id="errors-list"></div>
	</div>
</div>

<script>
const batchId = <?php echo json_encode($batch_id); ?>;
let pollInterval = null;

function updateProgress() {
	fetch('status.php?batch_id=' + encodeURIComponent(batchId))
		.then(response => response.json())
		.then(data => {
			if (data.error) {
				document.getElementById('status-text').textContent = 'Error: ' + data.error;
				if (pollInterval) {
					clearInterval(pollInterval);
				}
				return;
			}

			// Update progress bar
			const percent = data.progress_percent;
			document.getElementById('progress-fill').style.width = percent + '%';
			document.getElementById('progress-fill').textContent = percent + '%';

			// Update status text
			const statusText = `Processing: ${data.completed + data.failed} of ${data.total} complete ` +
				`(${data.pending} pending, ${data.processing} processing)`;
			document.getElementById('status-text').textContent = statusText;

			// If complete, show results
			if (data.is_complete) {
				if (pollInterval) {
					clearInterval(pollInterval);
				}

				document.getElementById('status-text').textContent = 'Processing complete!';
				document.getElementById('results-section').style.display = 'block';

				// Show quickstatements if we have results
				if (data.results.length > 0) {
					let qsText = '';
					data.results.forEach(r => {
						qsText += r.result + '\n';
					});
					document.getElementById('qs-data').value = qsText;
					document.getElementById('quickstatements-section').style.display = 'block';
				}

				// Show existing items
				if (data.existing_items.length > 0) {
					let html = '<ul>';
					data.existing_items.forEach(item => {
						html += '<li>' + item.pid + ' (already exists)</li>';
					});
					html += '</ul>';
					document.getElementById('existing-list').innerHTML = html;
					document.getElementById('existing-section').style.display = 'block';
				}

				// Show errors
				if (data.errors.length > 0) {
					let html = '<ul>';
					data.errors.forEach(item => {
						html += '<li>' + item.pid + ': ' + item.error + '</li>';
					});
					html += '</ul>';
					document.getElementById('errors-list').innerHTML = html;
					document.getElementById('errors-section').style.display = 'block';
				}
			}
		})
		.catch(err => {
			console.error('Error polling status:', err);
		});
}

// Start polling every 2 seconds
updateProgress();
pollInterval = setInterval(updateProgress, 2000);
</script>

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
