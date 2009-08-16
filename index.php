<?
include_once 'config.php';
include_once 'vendors/simple_html_dom.php';
include_once 'library/ikariam.php';

$ikariam['session']['base_url'] = getBaseURL($tld, $world);

$ikariam = updateResources();
if(!$ikariam['session']['logged_in'])
	doLogin($user, $pass);

$ikariam = getIslandIDs();

checkTransports();

//Town name is case sensitive; you can use your town's id instead if you want
//upgradeBuilding('Your Town Name','Name of Building');


//EXAMPLE of how to transport all wine that you can, from one island to another
//in increments of 500.  So if you have 1200 wood and 3 ships available, it'll just
//send over 1000 wood

$wine_city_id = '51345';
//Set the resource you want to monitor
$resource = $ikariam['cities'][$wine_city_id]['resources']['wine'];
$shipsAvailable = $ikariam['cities']['global']['resources']['ships']['available'];
$transportMax = $shipsAvailable * 500;

//See if we have at least 500 of that resource
foreach($ikariam['cities']['names'] as $city_id => $city){
	if(0 && $ikariam['cities'][$city_id]['resources']['wine'] <= 500 && $resource > 500 && $shipsAvailable >= 1){
		echo "Sent 500 wine to $city<br/>\n";
		$resources['wine'] = 500;
		transportFreight($wine_city_id,$city_id,$resources);
	}
}
if($resource >= 500){
	//Get a nice even ammount that's divisable by 500
	$amountToTransport = ($resource - ($resource % 500));
	//See how many ships we have
	$shipsAvailable = $ikariam['cities']['global']['resources']['ships']['available'];
	//Determine the max we can ship
	$transportMax = $shipsAvailable * 500;
	//If we have more to ship than we can transport, then set the amount to transport equal to the max we can ship
	if($amountToTransport > $transportMax)
		$amountToTransport = $transportMax;
	//Set up the resources array
	$resources = array(
		'wood' => $amountToTransport,
		'wine' => '0',
		'marble' => '0',
		'glass' => '0',
		'sulfur' => '0'
	);
	//Send it!
	transportFreight('Cloverfield', 'Wood for Sheep', $resources);
}

////Output examples
//$json[] = $ikariam['cities']['names']['49447'];

//$json = array_merge($json, $ikariam['cities']['global']['resources'],$ikariam['cities']['46774']['resources']);
//echo json_encode(array_values($json));

//echo json_encode($ikariam);

echo "<pre>"; print_r ($ikariam); die("</pre>");
