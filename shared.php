<?php

//----------------------------------------------------------------------------------------
function doi_to_agency($doi)
{
	$agency = '';
	
	$prefix_filename = dirname(__FILE__) . '/prefix.json';
	$json = file_get_contents($prefix_filename);
	$prefix_to_agency = json_decode($json, true);
	
	$parts = explode('/', $doi);
	$prefix = $parts[0];	
			
	if (isset($prefix_to_agency[$prefix]))
	{
		$agency = $prefix_to_agency[$prefix];
	}
	else
	{
		$url = 'https://doi.org/ra/' . $doi;
	
		$json = get($url);
	
		//echo $json;
	
		$obj = json_decode($json);
	
		if ($obj)
		{
			if (isset($obj[0]->RA))
			{
				$agency = $obj[0]->RA;
		
				$prefix_to_agency[$prefix] = $agency;
			}
	
		}
	}
	
	file_put_contents($prefix_filename, json_encode($prefix_to_agency, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	
	return $agency;
}

//----------------------------------------------------------------------------------------
// Fetch DOI
function get_work($doi)
{
	$obj = null;
	$json = '';
	
	$agency = doi_to_agency($doi);
	
	switch ($agency)
	{
		case 'Crossref':	
			$url = 'https://api.crossref.org/v1/works/' . $doi;
			$json = get($url);
			break;
	
		case 'JaLC':
		default:
			$url = 'https://doi.org/' . $doi;	
			$json = get($url, '', 'application/vnd.citationstyles.csl+json');		
			break;	
	}
	
	// echo $json;
	
	if ($json != '')
	{
		$obj = json_decode($json);
		
		if ($obj)
		{
		
			if (!isset($obj->message))
			{
				$obj->message = $obj;
			}
		
			post_process($obj);
		
			if ($agency != '')
			{
				$obj->message->DOIAgency = strtolower($agency);
			}
		}
	}
	return $obj;
}

//----------------------------------------------------------------------------------------
function post_process(&$obj)
{
	// type
	if (!isset($obj->message->type))
	{
		$obj->message->type = 'article-journal';
	}
		
	// ISSNs
	if (isset($obj->message->ISSN))
	{
		foreach ($obj->message->ISSN as &$issn)
		{
			// JaLC
			if (strlen($issn) == 8)
			{
				$issn = substr($issn, 0, 4) . '-' . substr($issn, 4);
			}
		}
	}
	else
	{
		// no ISSN
		if (isset($obj->message->{'container-title'}))
		{
			switch ($obj->message->{'container-title'})
			{
				case 'Ascomycete.org':
					$obj->message->ISSN[] = '2100-0840';
					$obj->message->ISSN[] = '2102-4995';					
					break;
				
				case 'Asian Herpetological Research':
					$obj->message->ISSN[] = '2095-0357';					
					break;
				
				case 'Raffles Bulletin of Zoology':
					$obj->message->ISSN[] = '0217-2445';
					$obj->message->ISSN[] = '2345-7600';
					break;
				
				case 'Rivista italiana di Paleontologia e Stratigrafia':
				case 'RIVISTA ITALIANA DI PALEONTOLOGIA E STRATIGRAFIA':
					$obj->message->ISSN[] = '2039-4942';
					$obj->message->ISSN[] = '0035-6883';
					break;
				
				case '昆虫学报':
				case 'Acta Entomologica Sinica':
					$obj->message->ISSN[] = '0454-6296';
					break;
					
				case '大阪市立自然史博物館研究報告 = Bulletin of the Osaka Museum of Natural History':
					$obj->message->ISSN[] = '0078-6675';
					break;
					
				default:
					break;
			}
		}
	
	


	}
}




?>
