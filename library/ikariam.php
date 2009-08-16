<?

/**
 * Uses curl to fetch the base_url.  It will accept params for POST, and a cookie file
 * if you have a cookie to send to the server.  It will also reset the session
 * cookie listed in $ikariam if the ikariam=deleted cookie comes back from the server.
 *
 * @param array $params Array of POST AND GET parameters to submit to server
 * @return string|bool Returns the html coming back from curl on success, and false
 * on failure.
 * @author Eric Boehs
 **/
function fetchContents($params=NULL){
	global $ikariam;

	//Get the base url
	$url = $ikariam['session']['base_url'];
	//Create the query string for GET requests
	if(isset($params['get'])){
		$uri = http_build_query($params['get']);
		$url = $url ."?". $uri;
	}

	//Get the cookie from previous session if it exists
	//FIXME: Possible problem with the cookie getting setting without a check
	$ikariam['session']['cookie'] = @file_get_contents('tmp/cookies.txt');
	$cookie = $ikariam['session']['cookie'];

	//Spoof the user agent in case the check for CURL and ban users for some reason
	$user_agent="Mozilla/5.0 (Macintosh; U; Intel Mac OS X 10.5; en-US; rv:1.9.1.2) Gecko/20090729 Firefox/3.5.2";

	//Init curl, return false on failure
	$ch = curl_init();
	if (!$ch)
		return false;

	//Set the url
	curl_setopt($ch, CURLOPT_URL, $url);
	//Check to see if a cookie is set, if it's not let's create one
	if($cookie === FALSE){
		$cookie = tempnam("/tmp", "CURLCOOKIE");  //Creates a file w/ a unique name
		$ikariam['session']['cookie'] = $cookie; //Sets the global session key to the new cookie
		//Make sure the directory we're about to write the cookie to exists, if not make it
		if (!is_dir('tmp'))
			mkdir('tmp');
		//I could probably just save the cookie itself to this file :/
		file_put_contents('tmp/cookies.txt', $cookie); //Store where the cookie file is
		//Fetch the cookie from the server and store it in the cookie file
		curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
	}else{
		//If $cookie is set to something besides null, send it to the server
		curl_setopt ($ch, CURLOPT_COOKIEFILE, $cookie); //Sends the actual contents of the cookie file
	}
	curl_setopt($ch, CURLOPT_ENCODING, 'gzip'); //Accept gzip encoding (much smaller page sizes, yay)
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); //Gets the server response??
	curl_setopt($ch, CURLOPT_HEADER, true); // Gets headers from server
	curl_setopt($ch, CURLOPT_USERAGENT, $user_agent); //Sets the user agent to what we specified earlier
	
	//If post is set, build it into a query string and POST it to the server
	if(isset($params['post'])){
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params['post']));
	}
	$response = curl_exec($ch); //Execute the query and save the response
	curl_close($ch); //Close the connection
	
	//If there's a body, split it form the header
	if(strpos($response, "\r\n\r\n"))
		list($header, $body) = explode("\r\n\r\n", $response, 2);
	else //just return the header if there's no body
		return $response; //This seems flawed.  If a del cookie reqs comes through it won't be caught

	//Unset the cookie file if the server says it's expired
	if(strpos($header, 'ikariam=deleted')){
		$ikariam['session']['logged_in'] = FALSE;
		if(isset($ikariam['session']['cookie']))
			unset($ikariam['session']['cookie']);
		if(file_exists('tmp/cookies.txt'))
			unlink('tmp/cookies.txt');
	}else{ //If all is good set logged_in
		$ikariam['session']['logged_in'] = TRUE;
	}
	
	//and return the body (for cleaner html parsing)
	return $body;
}

/**
 * Creates a base url to submit queries to.
 *
 * @param string $tld Top Level Domain of the server to connect to (e.g. .com,.org, etc)
 * @return string The base url derived
 * @author Eric Boehs
 **/
function getBaseURL($tld, $world){
	$base_url = "http://$world.ikariam.$tld/index.php";
	return $base_url;
}

/**
 * 
 * 
 * @param string $user 
 * @param string $pass 
 * @return string $resposne from fetchContents()
 * @author Eric Boehs
 **/
function doLogin($user, $pass){
	$params['get'] = array(
		'action' => 'loginAvatar',
		'function' => 'login'
	);
	$params['post'] = array(
		'name' => $user,
		'password' => $pass
	);
	getResources(fetchContents($params));
	return true;
}

function changeCurrentCity($city){
	global $ikariam;
	//TODO: If $city isn't numeric, do a lookup
	if(!isset($ikariam['hidden_inputs']['actionRequest']))
		return false;
	if($ikariam['current_city_id'] == $city)
		return false;

	$params['post'] = array(
		'action' => 'header',
		'function' => 'changeCurrentCity',
		'actionRequest' => $ikariam['hidden_inputs']['actionRequest'],
		'oldView' => 'city',
		'id' => $ikariam['current_city_id'],
		'cityId' => $city
	);
	$ikariam = getResources(fetchContents($params));
	return true;
}

function updateResources(){
	$params['get'] = array(
		'view' => 'city',
	);
	return getResources(fetchContents($params));
}

function getResources($html){
	global $ikariam;
	if(!function_exists('str_get_html'))
		return false;
	$html = str_get_html($html);

	//Get the city names
	foreach($html->find('#citySelect') as $option){
		foreach($option->find('option') as $e){
			$city_name = $e->plaintext;
			list($dev_null, $city_name) = explode("] ",$city_name);
			$ikariam['cities']['names'][$e->value] = $city_name;
		}
	}
	//Get the hidden inputs
	foreach($html->find('input') as $e){
		$ikariam['hidden_inputs'][$e->name] = $e->value;
	}

	//Get the current city name
	foreach($html->find('li.viewCity') as $li){
		foreach($li->find('a') as $e)
			$view_city_href = html_entity_decode(substr($e->href,1));
			parse_str($view_city_href, $view_city_href);
	}
	if(isset($view_city_href['id'])){
		$ikariam['current_city_id'] = $view_city_href['id'];
		$ikariam['current_city'] = $ikariam['cities']['names'][$view_city_href['id']];
	}
	
	//Get current island id
	foreach($html->find('li.viewIsland') as $li){
		foreach($li->find('a') as $e)
			$view_island_href = html_entity_decode(substr($e->href,1));
			parse_str($view_island_href, $view_island_href);
	}
	if(isset($view_island_href['id'])){
		$ikariam['current_island_id'] = $view_island_href['id'];
	}
	
	//Get ships
	foreach($html->find('li.transporters span') as $e){
		if($e->class === FALSE){
			$ships_raw = substr($e->plaintext,0,-1);
			$ships = explode('(', $ships_raw);
			$ikariam['cities']['global']['resources']['ships']['atsea'] =  $ships[1]-$ships[0];
			$ikariam['cities']['global']['resources']['ships']['available'] =  $ships[0];
			$ikariam['cities']['global']['resources']['ships']['total'] =  $ships[1];
		}
	}
	
	//Get the ambrosia
	foreach($html->find('li.ambrosia span') as $e){
		if($e->class === FALSE){
			$ikariam['cities']['global']['resources']['ambrosia'] = str_replace(",", "", $e->plaintext);
		}
	}
	
	//Get the gold
	foreach($html->find('#value_gold') as $e){
		$ikariam['cities']['global']['resources']['gold'] =  str_replace(",", "", $e->plaintext);
	}
	
	//Get the Resources
	foreach($html->find('#value_wood') as $e){
		$ikariam['cities'][$view_city_href['id']]['resources']['wood'] =  str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('#value_marble') as $e){
		$ikariam['cities'][$view_city_href['id']]['resources']['marble'] =  str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('#value_wine') as $e){
		$ikariam['cities'][$view_city_href['id']]['resources']['wine'] =  str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('#value_crystal') as $e){
		$ikariam['cities'][$view_city_href['id']]['resources']['glass'] =  str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('#value_sulfur') as $e){
		$ikariam['cities'][$view_city_href['id']]['resources']['sulfur'] =  str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('li.wood div.tooltip') as $e){
		$capacity = explode(': ', $e->plaintext);
		$ikariam['cities'][$view_city_href['id']]['resources']['capacity'] = str_replace(",", "", $capacity[1]);
	}
	
	//Get the buildings
	foreach($html->find('ul#locations') as $li){
		$i=0;
		foreach($li->find("a .textLabel") as $e){
			if($e->plaintext != 'In order to build here, you must research bureaucracy' && $e->plaintext != 'Free Building Ground'){
				if(substr($e->plaintext,'-19','-1') == "Under construction")
					$e->plaintext = str_replace(" (Under construction)",'',$e->plaintext);
				
				list($building, $level) = explode(' Level ', $e->plaintext);
				
				$ikariam['cities'][$ikariam['current_city_id']]['buildings'][$building]['position'] = $i;
				$ikariam['cities'][$ikariam['current_city_id']]['buildings'][$building]['level'] = $level;
			}
			$i++;
		}
	}
	
	return $ikariam;
}

function getIslandIDs(){
	global $ikariam;
	if(!isset($ikariam['cities']))
		return false;
	if(!function_exists('str_get_html'))
		return false;
	foreach($ikariam['cities']['names'] as $city => $city_name){
		//If the current city is already set in $ikariam then don't repopulate the data
		if(isset($ikariam['cities'][$city])){
			$ikariam['cities'][$city]['island'] = $ikariam['current_island_id'];
			continue;
		}
		changeCurrentCity($city);
		//Probably wouldn't do it this way if $city isn't unique - Need to check with Ikariam authors
		//If it's not unique, city should be a child of ['islands'][$island_id]
		$ikariam['cities'][$city]['island'] = $ikariam['current_island_id'];
	}
	return $ikariam;
}

function transportFreight($fromCity, $toCity, $resources){
	global $ikariam;

	if(is_numeric($fromCity))
		$fromCity = $fromCity;
	else
		$fromCity = array_search($fromCity,$ikariam['cities']['names']);

	if(is_numeric($toCity))
		$toCity = $toCity;
	else
		$toCity = array_search($toCity,$ikariam['cities']['names']);
	
	if(!is_numeric($fromCity) || !is_numeric($toCity))
		return '400';

	if($ikariam['current_city_id'] != $fromCity)
		changeCurrentCity($fromCity);
	if($ikariam['current_city_id'] != $fromCity)
		return false;
	
	$toIsland = $ikariam['cities'][$toCity]['island'];
	
	if(!isset($resources['wood']))
		$resources['wood'] = 0;
	if(!isset($resources['wine']))
		$resources['wine'] = 0;
	if(!isset($resources['marble']))
		$resources['marble'] = 0;
	if(!isset($resources['glass']))
		$resources['glass'] = 0;
	if(!isset($resources['sulfur']))
		$resources['sulfur'] = 0;

	$params['post'] = array(
		'action' => 'transportOperations',
		'function' => 'loadTransportersWithFreight',
		'actionRequest' => $ikariam['hidden_inputs']['actionRequest'],
		'destinationCityId' => $toCity,
		'id' => $toIsland,
		'cargo_resource' 	=> $resources['wood'],
		'cargo_tradegood1' => $resources['wine'],
		'cargo_tradegood2' => $resources['marble'],
		'cargo_tradegood3' => $resources['glass'],
		'cargo_tradegood4' => $resources['sulfur'],
		'transporters' => ceil(($resources['wood']+$resources['marble']+$resources['wine']+$resources['glass']+$resources['sulfur'])/500)
	);
	$response = fetchContents($params);
	getResources($response);
	checkResponseForErrors($response);
	if(isset($ikariam['errors']['error']) && $ikariam['errors']['error']){
		unset($ikariam['errors']);
		return false;
	}
	return true;
}

function upgradeBuilding($city, $building){
	global $ikariam;
	
	if(is_numeric($city))
		$city = $city;
	else
		$city = array_search($city,$ikariam['cities']['names']);
	
	if(!is_numeric($city))
		return '400';

	$params['get'] = array(
		'action' => 'CityScreen',
		'function' => 'upgradeBuilding',
		'id' => $city,
		'position' => $ikariam['cities'][$city]['buildings'][$building]['position'],
		'level' => $ikariam['cities'][$city]['buildings'][$building]['level'],
		'actionRequest' => $ikariam['hidden_inputs']['actionRequest']
	);
	return fetchContents($params);
}

function demolishBuilding($city, $building){
	global $ikariam;
	
	if(is_numeric($city))
		$city = $city;
	else
		$city = array_search($city,$ikariam['cities']['names']);
	
	if(!is_numeric($city))
		return '400';

	$params['get'] = array(
		'action' => 'CityScreen',
		'function' => 'downgradeBuilding',
		'id' => $city,
		'position' => $ikariam['cities'][$city]['buildings'][$building]['position'],
		'level' => $ikariam['cities'][$city]['buildings'][$building]['level'],
		'actionRequest' => $ikariam['hidden_inputs']['actionRequest']
	);
	return fetchContents($params);
}

function updateTownHall($city){
	
}

function checkTransports(){
	global $ikariam;
	if(!function_exists('str_get_html'))
		return false;
	
	$params['get'] = array(
		'view' => 'militaryAdvisorMilitaryMovements'
	);
	
	$response = fetchContents($params);
	$html = str_get_html($response);
	
	//Get the locationEvents table
	foreach($html->find('table.locationEvents tbody') as $table){
		$i=0; //Set a counter for counting ships
		
		//Loop through all the rows of the table
		foreach($table->find('tr') as $tr){
			//Skip the first row as it's the header
			if($i == 0){
				$i++; continue;
			}
			//Set the transport reference variable
			$t = &$ikariam['transports'][$i-1];
			//Get the time of arrival
			foreach($tr->find('td[title="Time of arrival"]') as $e){
				
				//The id of this element also specified the eventId for the transport
				//It begins with fleetRow so we'll stripe that away and set it in 1 line
				$eventId = str_replace('fleetRow','',$e->id);
				$t['event_id'] = $eventId;
				
				//Arrival times comes back space seperated - Make it an array.
				//Also reverse it so the values increase from smallest to largest
				$arrivaltime = array_reverse(explode(" ",$e->plaintext));
				
				//Get rid of the units (s for seconds, m for minutes, etc) and trim the spaces off
				foreach($arrivaltime as $key => $value)
					$arrivaltime[$key] = substr(trim($value),0,-1);
				//Set a reference so the longer variable doesn't have to be typed out each time
				$time = &$ikariam['transports'][$i-1]['time'];
				//Set the time for this transport in the $ikariam array
				@list($time['seconds'],$time['minutes'],$time['hours'],$time['days'],$time['weeks']) = $arrivaltime;
			}
			
			//Get the ships count and what's being shipped
			foreach($tr->find('div.unitBox') as $e){
				switch($e->title){
					case "Cargo Ship":
						$t['transport']['cargo_ship'] = $e->plaintext;
						break;
					case "Building material":
						$t['transport']['wood'] = $e->plaintext;
						break;
					case "Wine":
						$t['transport']['wine'] = $e->plaintext;
						break;
					case "Marble":
						$t['transport']['marble'] = $e->plaintext;
						break;
					case "Crystal Glass":
						$t['transport']['glass'] = $e->plaintext;
						break;
					case "Sulfur":
						$t['transport']['sulfur'] = $e->plaintext;
						break;
					default:
						$t['transport'][str_replace(" ","_",strtolower($e->title))] = $e->plaintext;
				}
			}
			
			//Get the orgin city and user
			foreach($tr->find('td[title="Origin"]') as $e){
				foreach($e->find('a') as $a){
					parse_str($a->href, $href);	
					$t['origin']['city_id'] = $href['cityId'];
				}
				list($t['origin']['city'],$t['origin']['user']) = explode(" (",substr($e->plaintext,0,-1));
			}
			
			//Get the target city and user
			foreach($tr->find('td[title="Target"]') as $e){
				foreach($e->find('a') as $a){
					parse_str($a->href, $href);	
					$t['target']['city_id'] = $href['cityId'];
				}
				list($t['target']['city'],$t['target']['user']) = explode(" (",substr($e->plaintext,0,-1));
			}
			
			//Get mission and arrow
			foreach($tr->find('td img') as $e){
				if($e->parent()->title){
					list($mission,$status) = explode('_(',str_replace(" ","_",(str_replace(')','',strtolower($e->parent()->title)))));
					$mission = str_replace('(','_',$mission);
					$t['mission'] = $mission;
					$t['status'] = $status;
				}
			}
			$i++;
		}
	}
	getResources($response);
	return true;
}

function updateAllTownHalls(){
	
}

/**
 * This will check the status of the response html returned from fetchContents
 *
 * @param string $html - HTML tag for 
 * @return int Status Code
 * @author Eric Boehs
 **/
function checkResponseForErrors($html){
	global $ikariam;
	if(!function_exists('str_get_html'))
		return false;
	$html = str_get_html($html);

	//Get the city names
	foreach($html->find('#breadcrumbs span.textLabel') as $e){
		if($e->plaintext == 'Error!')
			$error = TRUE;
		else
			$error = FALSE;
	}
	if(isset($error) && $error){
		$ikariam['errors']['error'] = true;
		foreach($html->find('.content ul li') as $error){
			$ikariam['errors']['messages'][] = $error->plaintext;
		}
	}
	return;
}