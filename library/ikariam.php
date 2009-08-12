<?

function fetchContents($url, $params=NULL, $cookie=NULL, $debug=FALSE){
	global $ikariam;
	$user_agent="Mozilla/5.0 (Macintosh; U; Intel Mac OS X 10.5; en-US; rv:1.9.1.2) Gecko/20090729 Firefox/3.5.2";
	$ch = curl_init();
	if (!$ch)
		die( "Cannot allocate a new PHP-CURL handle" );

	curl_setopt($ch, CURLOPT_URL, $url);
	if($cookie === NULL){
		$cookie = tempnam("/tmp", "CURLCOOKIE");
		$ikariam['session']['cookie'] = $cookie;
		if (!is_dir('tmp')){
			mkdir('tmp'); 
		}
		file_put_contents('tmp/cookies.txt', $cookie);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
	}else{
		curl_setopt ($ch, CURLOPT_COOKIEFILE, $cookie);
	}
	curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
	if($params != NULL){
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
	}
	$response = curl_exec($ch);
	curl_close($ch);

	if(strpos($response, "\r\n\r\n"))
		list($header, $body) = explode("\r\n\r\n", $response, 2);
	else
		return $response;

	if(strpos($header, 'ikariam=deleted')){
		$ikariam['session']['logged_in'] = FALSE;
		if(isset($ikariam['session']['cookie']))
			unset($ikariam['session']['cookie']);
	}else{
		$ikariam['session']['logged_in'] = TRUE;
	}

	if($debug){
		echo "<pre>"; print_r ($header); echo "</pre>";
	}
	return $body;
}

function getBaseURL($tld, $world){
	$worlds = array(
		'Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta', 'Iota', 'Kappa'
	);

	$world = array_search($world, $worlds);
	$world++;

	$base_url = "http://s$world.ikariam.$tld/index.php";
	return $base_url;
}

function checkLogin(){
	global $ikariam;
	$ikariam['session']['cookie'] = @file_get_contents('tmp/cookies.txt');
	$base_url = $ikariam['session']['base_url'];
	$uri = '';
	$url = $base_url ."?". $uri;
	if(!isset($ikariam['session']['cookie']))
		$ikariam['session']['cookie'] = NULL;
	
	$response = fetchContents($url, NULL, $ikariam['session']['cookie']);
	
	if(isset($ikariam['session']['logged_in']) && $ikariam['session']['logged_in'])
		return true;
	else
		return false;
}

function doLogin($user, $pass){
	global $ikariam;
	if(checkLogin($ikariam))
		return true;
	//echo "Logging in<br/>\n";
	$base_url = $ikariam['session']['base_url'];
	$uri = 'action=loginAvatar&function=login';
	$url = $base_url ."?". $uri;
	$params = array(
		'name' => $user,
		'password' => $pass
	);
	if(!isset($ikariam['session']['cookie']))
		$ikariam['session']['cookie'] = NULL;
	$response = fetchContents($url, $params, $ikariam['session']['cookie']);

	$ikariam['session']['logged_in'] = TRUE;
	return $response;
}

function changeCurrentCity($city){
	global $ikariam;
	if(!isset($ikariam['hidden_inputs']['actionRequest']))
		return false;
	if($ikariam['current_city_id'] == $city)
		return false;

	$base_url = $ikariam['session']['base_url'];
	$uri = '';
	$url = $base_url ."?". $uri;
	$params = array(
		'action' => 'header',
		'function' => 'changeCurrentCity',
		'actionRequest' => $ikariam['hidden_inputs']['actionRequest'],
		'oldView' => 'city',
		'id' => $ikariam['current_city_id'],
		'cityId' => $city
	);
	if(!isset($ikariam['session']['cookie']))
		$ikariam['session']['cookie'] = NULL;
	$response = fetchContents($url, $params, $ikariam['session']['cookie']);
	$ikariam = getResources($response);
	return true;
}

function updateResources(){
	global $ikariam;
	cleanIkariam();
	$base_url = $ikariam['session']['base_url'];
	$uri = '';
	$url = $base_url ."?". $uri;
	$url = $base_url;
	if(!isset($ikariam['session']['cookie']))
		$ikariam['session']['cookie'] = NULL;
	$response = fetchContents($url, NULL, $ikariam['session']['cookie'],FALSE);
	return getResources($response);
}

function getResources($html){
	global $ikariam;
	if(!function_exists('str_get_html'))
		return false;
	$html = str_get_html($html);

	//Get the city names
	foreach($html->find('#citySelect') as $option){
		foreach($option->find('option') as $e)
			$ikariam['cities']['names'][$e->value] = $e->plaintext;
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
		list($dev_null, $ikariam['current_city']) = explode("] ",$ikariam['cities']['names'][$view_city_href['id']]);
	}

	foreach($html->find('li.viewIsland') as $li){
		foreach($li->find('a') as $e)
			$view_island_href = html_entity_decode(substr($e->href,1));
			parse_str($view_island_href, $view_island_href);
	}
	if(isset($view_island_href['id'])){
		$ikariam['current_island_id'] = $view_island_href['id'];
	}
	//Get the ships
	foreach($html->find('li.transporters span') as $e){
		if($e->class === FALSE){
			$ships_raw = substr($e->plaintext,0,-1);
			$ships = explode('(', $ships_raw);
			$ikariam['cities']['global']['resources']['ships']['atsea'] =  $ships[1]-$ships[0];
			$ikariam['cities']['global']['resources']['ships']['available'] =  $ships[0];
			$ikariam['cities']['global']['resources']['ships']['total'] =  $ships[1];
			$ikariam['cities']['global']['resources']['ships'] = "$ships[0]/ $ships[1]";
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
		$ikariam['cities'][$view_city_href['id']]['resources']['crystal'] =  str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('#value_sulfur') as $e){
		$ikariam['cities'][$view_city_href['id']]['resources']['sulfur'] =  str_replace(",", "", $e->plaintext);
	}
	foreach($html->find('li.wood div.tooltip') as $e){
		$capacity = explode(': ', $e->plaintext);
		$ikariam['cities'][$view_city_href['id']]['resources']['capacity'] = str_replace(",", "", $capacity[1]);
	}
	return $ikariam;
}

function getIslandIDs(){
	global $ikariam;
	if(!isset($ikariam['cities']))
		return false;
	foreach($ikariam['cities']['names'] as $city => $city_name){
		if(!function_exists('str_get_html'))
			return false;
		changeCurrentCity($city);
		//Probably wouldn't do it this way if $city isn't unique - Need to check with Ikariam authors
		$ikariam['cities'][$city]['island'] = $ikariam['current_island_id'];
	}
	return $ikariam;
}

function transportFreight($fromCity, $toIsland, $toCity, $wood=0, $marble=0, $wine=0, $crystal=0, $sulfur=0){
	//Not fully implemented
	global $ikariam;
	if($ikariam['current_city_id'] != $fromCity)
		changeCurrentCity($fromCity);
	if($ikariam['current_city_id'] != $fromCity)
		return false;
	if(!isset($ikariam['hidden_inputs']['actionReuest']))
		$ikariam = updateResources($ikariam);

	$base_url = $ikariam['session']['base_url'];
	$uri = '';
	$url = $base_url ."?". $uri;
	$url = $base_url;
	$params = array(
		'action' => 'transportOperations',
		'function' => 'loadTransportersWithFreight',
		'actionRequest' => $ikariam['hidden_inputs']['actionRequest'],
		'destinationCityId' => $toCity,
		'id' => $toIsland,
		'cargo_resource' => $wood,
		'cargo_resource1' => $marble,
		'cargo_resource2' => $wine,
		'cargo_resource3' => $crystal,
		'cargo_resource4' => $sulfur,
		'transporters' => ceil(($wood+$marble+$wine+$crystal+$sulfur)/500)
	);
	if(!isset($ikariam['session']['cookie']))
		$ikariam['session']['cookie'] = NULL;
	$response = fetchContents($url, $params, $ikariam['session']['cookie']);
	echo "<pre>"; print_r ($response); die("</pre>");
	return true;
}

function cleanIkariam(){
	//Not fully implemented
	global $ikariam;
	if(isset(
			$ikariam['cities'],
			$ikariam['hidden_inputs'],
			$ikariam['current_island_id'],
			$ikariam['current_city_id'],
			$ikariam['current_city'],
			$ikariam['ships'],
			$ikariam['resources']
		)
	){
		unset(
			$ikariam['cities'],
			$ikariam['hidden_inputs'],
			$ikariam['current_island_id'],
			$ikariam['current_city_id'],
			$ikariam['current_city'],
			$ikariam['ships'],
			$ikariam['resources']
		);
	}
}