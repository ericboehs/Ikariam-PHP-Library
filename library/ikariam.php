<?
// =================
// = Page Fetching =
// =================

function getBaseURL($tld, $world){
	$base_url = "http://$world.ikariam.$tld/index.php";
	return $base_url;
}

function fetchPage($url, $cookie=NULL, $params=NULL){

	//Create the query string for GET requests
	if(isset($params['get'])){
		$uri = http_build_query($params['get']);
		$url = $url ."?". $uri;
	}

	//Get the cookie from previous session if it exists
	//FIXME: Possible problem with the cookie getting set without a check
	$return['session']['cookie'] = @file_get_contents('tmp/cookies.txt');

	//Spoof the user agent in case the check for CURL and ban users for some reason
	$user_agent="Mozilla/5.0 (Macintosh; U; Intel Mac OS X 10.5; en-US; rv:1.9.1.2) Gecko/20090729 Firefox/3.5.2";

	//Init curl, return false on failure
	$ch = curl_init();
	if (!$ch)
		return false;

	//Set the url
	curl_setopt($ch, CURLOPT_URL, $url);
	//Check to see if a cookie is set, if it's not let's create one
	if($cookie === NULL || $cookie === FALSE){
		$cookie = tempnam("/tmp", "CURLCOOKIE");  //Creates a file w/ a unique name
		$return['session']['cookie'] = $cookie; //Sets the global session key to the new cookie
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
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); //Gets the server response
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
		$return['header'] = $response; //This seems flawed.  If a del cookie reqs comes through it won't be caught

	$return['header'] = $header;
	$return['body'] = $body;
	
	return $return;
}

function checkLogin($url){
	global $ikariam;
	$cookie = @file_get_contents('tmp/cookies.txt');
	$params['get'] = array(
		'view' => 'city',
	);

	//Perform page request
	$response = fetchPage($url, $cookie, $params);
	
	$ikariam['session']['cookie'] = $response['session']['cookie'];
	
	//Send html to checkCookie to determine if an ikariam=deleted was issued
	$logged_in = checkCookie($response['header']);
	
	//If that comes back true than we are logged in (return true)
	if($logged_in)
		return TRUE;
	else //If it comes back false then return false
		return FALSE;
}

function checkCookie($header){
	global $ikariam;
	//Unset the cookie file if the server says it's expired
	if(strpos($header, 'ikariam=deleted')){
		$ikariam['session']['logged_in'] = FALSE;
		$logged_in = FALSE;
		if(isset($ikariam['session']['cookie']))
			unset($ikariam['session']['cookie']);
		if(file_exists('tmp/cookies.txt'))
			unlink('tmp/cookies.txt');
	}else{ //If all is good set logged_in
		$logged_in = TRUE;
		$ikariam['session']['logged_in'] = TRUE;
	}
	return $logged_in;
}

function doLogin($url, $user, $pass){
	global $ikariam;
	//Set time stamp
	$ikariam['session']['login_time'] = time();
	
	$params['get'] = array(
		'action' => 'loginAvatar',
		'function' => 'login'
	);
	$params['post'] = array(
		'name' => $user,
		'password' => $pass
	);
	$response = fetchPage($url, NULL, $params);
	$ikariam['session']['cookie'] = $response['session']['cookie'];
	$ikariam['current_player'] = $user;
	
	setIkariamFile();
	
	return true;
}

function doAction($params=NULL){
	global $ikariam, $island_names, $debug;

	//Make sure the simple dom parser is loaded
	if(!function_exists('str_get_html'))
		die("str_get_html() does not exist.");
	
	//Set some variables
	$url = $ikariam['session']['base_url'];
	$cookie = $ikariam['session']['cookie'];
	
	//Add the action request in get requests
	if(isset($params['get']['actionRequest']) && $params['get']['actionRequest'] === TRUE){
		$params['get']['actionRequest'] = $ikariam['hidden_inputs']['actionRequest'];
	}
	
	//Add the action request in post requests
	if(isset($params['post'])){
		if(!isset($ikariam['hidden_inputs']['actionRequest']))
			return false;
		$params['post']['actionRequest'] = $ikariam['hidden_inputs']['actionRequest'];
	}
	
	if($debug) echo "<pre>"; 
	if($debug) print_r ($params);
	
	//Perform the page fetch
	$response = fetchPage($url, $cookie, $params);
	
	//Make sure cookie's valid
	if(!checkCookie($response['header']))
		die("Cookie expired!");
	
	if($debug > 1) {
		echo "<pre>";
		print_r($response['body']);
	}
	
	//Get the html object for the response
	$html_obj = str_get_html($response['body']);
	
	//Check for errors
	if(checkResponseForErrors($html_obj)){
		if($debug){
			echo "<pre>"; 
			print_r (debug_backtrace());
			foreach($ikariam['errors']['messages'] as $error)
				echo $error."<br />\n";
			die();
		}else
			return $html_obj;
	}

	//Update the hidden input variables
	$ikariam['hidden_inputs'] = getHiddenInputs($html_obj);
	
	//Get the city names
	$ikariam['cities']['names'] = getCityNames($html_obj);

	//Get the current city name and id
	$ikariam['current_city_id'] = getCurrentCityID($html_obj);
	
	$ikariam['current_city'] = $ikariam['cities']['names'][$ikariam['current_city_id']];
	$ikariam['current_island_id'] = getCurrentIslandID($html_obj);
	$ikariam['current_island'] = $island_names[$ikariam['current_island_id']];
	
	//Get the global resources
	$ikariam['cities']['global']['resources']['ships'] = getShips($html_obj); //Get ships
	$ikariam['cities']['global']['resources']['ambrosia'] = getAmbrosia($html_obj); //Get the ambrosia
	$ikariam['cities']['global']['resources']['gold'] = getGold($html_obj); //Get the gold

	//Fetch the current resources if they exist
	
	$current_city_resources = getCityInfo($html_obj);
	if($current_city_resources != NULL && $current_city_resources != FALSE)
		$ikariam['cities'][$ikariam['current_city_id']] = $current_city_resources;
	
	setIkariamFile();
	
	//Return
	return $html_obj;
}

function checkResponseForErrors($html){
	//FIXME: Make this return a list of errors, not stick them in the ikariam array
	global $ikariam;

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
	return $error;
}

//Unused
function setIkariamFile(){
	global $ikariam, $user;
	// Create a unique filename
	$ikariam_file = md5($user);
	// Encode data into JSON
	$ikariam_json = json_encode($ikariam);
	// Save it to a file
	file_put_contents("tmp/$ikariam_file.json",$ikariam_json);
	return true;
}

//Unused
function getIkariamFile(){
	global $ikariam, $user;
	// Generate the unique filename
	$ikariam_file = md5($user);
	// Get the file
	$ikariam_json = file_get_contents("tmp/$ikariam_file.json");
	// Decode data out of JSON
	$ikariam = json_decode($ikariam_json,1);
	//Return
	return true;
}

//Unused
function dropOldData($keys){
	global $ikariam;
	foreach($keys as $key){
		if(isset($ikariam[$key]['last_update'])){
			$secondsSinceUpdate = time() - $ikariam[$key]['last_update'];
			if($secondsSinceUpdate > 30){
				unset($ikariam[$key]);
				echo "UNSET $key<br />\n";
			}
		}
	}
}



// ===========
// = General =
// ===========

function changeCurrentCity($city){
	global $ikariam;
	//TODO: If $city isn't numeric, do a lookup
	
	if($ikariam['current_city_id'] == $city)
		return false;
	$params['get'] = array(
		'view' => 'city',
	);
	$params['post'] = array(
		'action' => 'header',
		'function' => 'changeCurrentCity',
		'oldView' => 'city',
		'id' => $ikariam['current_city_id'],
		'cityId' => $city
	);
	if(doAction($params) !== FALSE)
		return TRUE;
	else
		return FALSE;
}


// ================================
// = Global Information Gatherers =
// ================================

function getCityInfo($html_obj){
	if(!is_object($html_obj))
		return false;
	global $island_names;
	
	//Get the city names
	$city_names = getCityNames($html_obj);

	//Get the current city name and id
	$city_id = getCurrentCityID($html_obj);
	$city['name'] = $city_names[$city_id];
	$city['last_update'] = time();
	
	//Get current island id
	$current_island_id= getCurrentIslandID($html_obj);
	
	//Get current island name
	$current_island = $island_names[$current_island_id];
	
	//Set city's island id
	$city['island_id'] = $current_island_id;
	
	//Set city's island name
	$city['island'] = $current_island;
	
	//Get city's trade good
	$city['trade_good'] = getCityTradeGood($html_obj, $city_id);
	
	//Get the resources
	$city['resources'] = getResourceValues($html_obj);
	
	$buildings = getBuildingLevels($html_obj);
	if($buildings != NULL && $buildings != FALSE)
		$city['buildings'] = $buildings;

	//Return
	return $city;
}

function getHiddenInputs($html){
	if(!is_object($html))
		return false;
	foreach($html->find('input[type=hidden]') as $e){
		$hidden_inputs[$e->name] = $e->value;
	}
	return $hidden_inputs;
}

function getCityNames($html){
	if(!is_object($html))
		return false;
	//Get the city names
	foreach($html->find('#citySelect') as $option){
		foreach($option->find('option') as $e){
			if(strpos($e->class,'ccupiedCities'))
				break;
			if(strpos($e->class,'eployedCities'))
				break;
			$city_name = $e->plaintext;
			list($dev_null, $city_name) = explode("] ",$city_name);
			$city_names_array[$e->value] = $city_name;
		}
	}
	return $city_names_array;
}

function getCurrentCityID($html){
	if(!is_object($html))
		return false;
	foreach($html->find('li.viewCity') as $li){
		foreach($li->find('a') as $e){
			$view_city_href = html_entity_decode(substr($e->href,1));
			parse_str($view_city_href, $view_city_href);
		}
	}
	if(isset($view_city_href['id']))
		$city_id = (int)$view_city_href['id'];
	return $city_id;
}

function getCurrentIslandID($html){
	if(!is_object($html))
		return false;
	foreach($html->find('li.viewIsland') as $li){
		foreach($li->find('a') as $e){
			$view_island_href = html_entity_decode(substr($e->href,1));
			parse_str($view_island_href, $view_island_href);
		}
	}
	if(isset($view_island_href['id'])){
		$current_island_id = (int)$view_island_href['id'];
	}
	return $current_island_id;
}

function getShips($html){
	if(!is_object($html))
		return false;
	foreach($html->find('li.transporters span') as $e){
		if($e->class === FALSE){
			$ships_raw = substr($e->plaintext,0,-1);
			$ships = explode(' (', $ships_raw);
			$ships_array['at_sea'] = $ships[1]-$ships[0];
			$ships_array['available'] =  (int)$ships[0];
			$ships_array['total'] =  (int)$ships[1];
		}
	}
	return $ships_array;
}

function getAmbrosia($html){
	if(!is_object($html))
		return false;
	foreach($html->find('li.ambrosia span') as $e){
		if($e->class === FALSE){
			$ambrosia = (int)str_replace(",", "", $e->plaintext);
		}
	}
	return $ambrosia;
}

function getGold($html){
	if(!is_object($html))
		return false;
	foreach($html->find('#value_gold') as $e){
		$gold = (int)str_replace(",", "", $e->plaintext);
	}
	return $gold;
}

function getResourceValues($html){
	if(!is_object($html))
		return false;
	foreach($html->find('#value_wood') as $e){
		$resources_array['wood'] =  (int)str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('#value_marble') as $e){
		$resources_array['marble'] =  (int)str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('#value_wine') as $e){
		$resources_array['wine'] =  (int)str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('#value_crystal') as $e){
		$resources_array['glass'] =  (int)str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('#value_sulfur') as $e){
		$resources_array['sulfur'] =  (int)str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('li.wood div.tooltip') as $e){
		if(strpos($e->plaintext,'rading Post')){
			$capacity_lines = explode("\r\n\t", str_replace(",", "", $e->plaintext));
			$current_capacity = explode(': ', $capacity_lines[0]);
			$current_trading = explode(': ', $capacity_lines[1]);
			$resources_array['capacity'] = (int)($current_capacity[1] + $current_trading[1]);
		}else{
			$capacity = explode(': ', $e->plaintext);
			$resources_array['capacity'] = (int)str_replace(",", "", $capacity[1]);
		}
	}
	return $resources_array;
}

function getCityTradeGood($html,$city_id){
	if(!is_object($html))
		return false;
	foreach($html->find('#citySelect') as $option){
		foreach($option->find('option') as $e){
			if(strpos($e->class,'ccupiedCities'))
				break;
			$trade_good[$e->value] = $e->title;
		}
	}
	if(isset($trade_good)){
		$trade_good_long = str_replace('Trade good: ','',$trade_good[$city_id]);
		switch($trade_good_long){
			case "Wine":
				$current_trade_good = 'wine';
				break;
			case "Marble":
				$current_trade_good = 'marble';
				break;
			case "Crystal Glass":
				$current_trade_good = 'glass';
				break;
			case "Sulfur":
				$current_trade_good = 'sulfur';
				break;
			default:
				$current_trade_good = str_replace(' ','_',strtolower($trade_good_long));
		}
		$trade_good = $current_trade_good;
	}
	return $trade_good;
}

function getBuildingLevels($html){
	if(!is_object($html))
		return false;
	$i=0;
	foreach($html->find('ul#locations li') as $li){
		foreach($li->find("a .textLabel") as $e){
			if($e->plaintext != 'In order to build here, you must research bureaucracy' && $e->plaintext != 'Free Building Ground'){
				if(substr($e->plaintext,'-19','-1') == "Under construction"){
					$e->plaintext = str_replace(" (Under construction)",'',$e->plaintext);
					$upgrading = TRUE;
				}else{
					$upgrading = FALSE;
				}
				
				list( , $level) = explode(' Level ', $e->plaintext);
				$building = $li->class;
				
				if($building == "townhall")
					continue;
				
				$building_levels_array[$building]['position'] = $i;
				$building_levels_array[$building]['level'] = (int)$level;
				if($upgrading){
					$building_levels_array[$building]['upgrading'] = TRUE;
				}
			}
			$i++;
		}
	}
	return $building_levels_array;
}


// ==================
// = Generic Actions =
// ==================

function transportFreight($fromCity, $toCity, $resources){
	global $ikariam;

	if(!is_numeric($fromCity) || !is_numeric($toCity))
		die('City IDs must be numeric');
	
	if(!is_numeric($fromCity) || !is_numeric($toCity) || $fromCity == $toCity)
		return '400';

	if($ikariam['current_city_id'] != $fromCity)
		changeCurrentCity($fromCity);
	if($ikariam['current_city_id'] != $fromCity)
		return false;
	
	$toIsland = $ikariam['cities'][$toCity]['island_id'];
	
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
	
	$transports = ($resources['wood']+$resources['marble']+$resources['wine']+$resources['glass']+$resources['sulfur'])/500;
		
	$params['post'] = array(
		'action' => 'transportOperations',
		'function' => 'loadTransportersWithFreight',
		'actionRequest' => TRUE,
		'destinationCityId' => $toCity,
		'id' => $toIsland,
		'cargo_resource'   => $resources['wood'],
		'cargo_tradegood1' => $resources['wine'],
		'cargo_tradegood2' => $resources['marble'],
		'cargo_tradegood3' => $resources['glass'],
		'cargo_tradegood4' => $resources['sulfur'],
		'transporters'     => $transports
	);
	$response = doAction($params);
	return true;
}

function aboutFleetOperation($event_id){
	$params['get'] = array(
		'action' => 'transportOperations',
		'function' => 'abortFleetOperation',
		'eventId' => $event_id,
		'actionRequest' => TRUE,
	);
	$response = doAction($params);
	return true;
}

function getTransports(){
	global $ikariam;
	
	$params['get'] = array(
		'view' => 'militaryAdvisorMilitaryMovements'
	);
	
	$html = doAction($params);
	
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
				$t['event_id'] = (int)$eventId;
				
				//Arrival times comes back space seperated - Make it an array.
				//Also reverse it so the values increase from smallest to largest
				$arrivaltime = explode(" ",$e->plaintext);
				
				//Set a reference so the longer variable doesn't have to be typed out each time
				$time = &$ikariam['transports'][$i-1]['time'];
				
				//Loop through each unit and set the time accoringly
				foreach($arrivaltime as $key => $value){
					$unit = substr($value,-1,1);
					$value = substr($value,0,-1);
					switch($unit){
						case "s":
							$time['seconds']  = (int)$value;
							break;
						case "m":
							$time['minutes']  = (int)$value;
							break;
						case "h":
							$time['hours']    = (int)$value;
							break;
						case "d":
							$time['days']     = (int)$value;
							break;
						case "w":
							$time['weeks']    = (int)$value;
							break;
						default:
							$time[$unit]      = (int)$value;
					}
				}
			}
			
			//Get the ships count and what's being shipped
			foreach($tr->find('div.unitBox') as $e){
				switch($e->title){
					case "Building material":
						$t['transport']['wood']  = (int)$e->plaintext;
						break;
					case "Crystal Glass":
						$t['transport']['glass'] = (int)$e->plaintext;
						break;
					default:
						$t['transport'][str_replace(" ","_",strtolower($e->title))] = (int)$e->plaintext;
				}
			}
			
			//Get the orgin city and user
			foreach($tr->find('td[title="Origin"]') as $e){
				foreach($e->find('a') as $a){
					parse_str($a->href, $href);	
					$t['origin']['city_id'] = (int)$href['cityId'];
				}
				list($t['origin']['city'],$t['origin']['user']) = explode(" (",substr($e->plaintext,0,-1));
			}
			
			//Get the target city and user
			foreach($tr->find('td[title="Target"]') as $e){
				foreach($e->find('a') as $a){
					parse_str($a->href, $href);	
					$t['target']['city_id'] = (int)$href['cityId'];
				}
				list($t['target']['city'],$t['target']['user']) = explode(" (",substr($e->plaintext,0,-1));
			}
			
			//Get mission and arrow
			foreach($tr->find('td img') as $e){
				if($e->parent()->title){
					list($mission,$status) = explode('_(',str_replace(" ","_",(str_replace(')','',strtolower($e->parent()->title)))));
					$mission = str_replace('(','_',$mission);
					$t['mission'] = $mission;
					$t['status'] = $status;  //FIXME: If there is no arrows and the status is return then status should = loading
				}
			}
			$i++;
		}
	}
	return true;
}

function upgradeBuilding($city, $building, $position){
	global $ikariam;
	
	if(!is_numeric($city))
		return '400';

	$params['get'] = array(
		'action' => 'CityScreen',
		'function' => 'upgradeBuilding',
		'id' => $city,
		'position' => $ikariam['cities'][$city]['buildings'][$building]['position'],
		'level' => $ikariam['cities'][$city]['buildings'][$building]['level'],
		'actionRequest' => TRUE
	);
	return doAction($params);
}

function demolishBuilding($city, $building){
	global $ikariam;
	
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

function getBuildingUpgradeCost($city, $building, $position){
	$params['get'] = array(
		'view' => $building,
		'id' => $city,
		'position' => $position,
	);
	$html = doAction($params);
	
	
	foreach($html->find('#buildingUpgrade  div.content ul.resources li') as $li){
		$class = $li->class;
		if(strpos($class,' '))
			list($class) = explode(' ',$class);
		
		list(,$amount) = explode(': ',str_replace(',','',$li->plaintext));
		if($class == "time"){
			$time = explode(' ',$amount);
			if(isset($amount))
				unset($amount);
			foreach($time as $key => $value){
				$unit = substr($value,-1,1);
				$value = substr($value,0,-1);
				switch($unit){
					case "s":
						$amount['seconds']  = (int)$value;
						break;
					case "m":
						$amount['minutes']  = (int)$value;
						break;
					case "h":
						$amount['hours']    = (int)$value;
						break;
					case "d":
						$amount['days']     = (int)$value;
						break;
					case "w":
						$amount['weeks']    = (int)$value;
						break;
					default:
						$amount[$unit]      = (int)$value;
				}
			}
		}
		
		$cost[$class] = $amount;
	}
	return $cost;
}

function getBuildingDowngradeCost($city, $building, $position){

}

// =============
// = Town Hall =
// =============

function updateTownHall($city){
	
}

function updateAllTownHalls(){
	
}

// ==============
// = Safe House =
// ==============

function getSpiesAwaitingTraining(){}

function getSpies(){}

function spyMission($city, $spy, $mission, $position){
	//Setup Params
	$params['get'] = array(
		'action' => 'Espionage',
		'function' => 'executeMission',
		'actionRequest' => TRUE,
		'id' => $city,
		'position' => $position,
		'spy' => $spy,
		'mission' => $mission,
	);
	
	//Do Request
	$html = doAction($params);
	
	//Return response object
	return $html;
}

function getSpyReport($city, $spy, $report_id, $position){
	//Setup Params
	
	$params['get'] = array(
		'view' => 'safehouseReports',
		'id' => $city,
		'spy' => $spy,
		'position' => $position,
		'reportId' => $report_id
	);
	
	//Do Request
	$html = doAction($params);
	
	//Return response object
	return $html;
}

function parseReportSpyWarehouse($html){
	if(!is_object($html))
		return false;
	
	if($html->find('table.record tbody tr.status td',1)->plaintext != 'Mission successfully accomplished!')
		return false;
	
	$i=-1;
	foreach($html->find('table#resources tbody tr') as $units){
		$i++;
		if($i == 0)
			continue;
		
		foreach($units->find('td') as $unit_name => $unit){
			if(count($unit->children(0)) == 1)
				$resource_name = $unit->children(0)->getAttribute('alt');
			else
				$resource_value = $unit->plaintext;
			
			
			//echo "Image title:    {$unit->img->title}<br/>\n";
			//echo "Unit Plaintext: {$unit->plaintext} <br/>\n";
		}
		if($resource_name == "Building material")
			$resource_name = "wood";
		if($resource_name == "Crystal Glass")
			$resource_name = "glass";
		
		$resources[strtolower($resource_name)] = str_replace(',','',$resource_value);
	}
	
	$resources_total = 0;
	
	foreach($resources as $resource)
		$resources['total'] += $resource;
	$resources['ship_loads'] = ceil($resources['total'] / 500);
	
	return $resources;
}

function getSpyReports(){}

function pillageCity($island,$city,$troops,$transporters){
	//Setup Params
	$params['get'] = array(
		'view' => 'plunder',
		'destinationCityId' => $city,
	);
	
	$params['post'] = array(
		'action' => 'transportOperations',
		'function' => 'sendArmyPlunderLand',
		'actionRequest' => TRUE,
		'id' => $island,
		'destinationCityId' => $city,
	);
	foreach($troops as $troop_name => $troop)
		$params['post'][$troop_name] = $troop;
	$params['post']['transporter'] = $transporters;
	
	//Do Request
	$html = doAction($params);
	
	//Return response object
	return $html;
}

function recallSpy(){}



