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


//echo "<pre>".
//transportFreight($ikariam['current_city_id'],'176','46774','1000','0','0','0','0');

//$json[] = $ikariam['cities']['names']['49447'];

//$json = array_merge($json, $ikariam['cities']['global']['resources'],$ikariam['cities']['46774']['resources']);
//echo json_encode(array_values($json));

//echo json_encode($ikariam);

//Town name is case sensitive; you can use your town's id instead if you want
//upgradeBuilding('Your Town Name','Name of Building');


//EXAMPLE of how to transport all wood that you can, from one island to another
//in increments of 500.  So if you have 1200 wood and 3 ships available, it'll just
//send over 1000 wood

//Set the resource you want to monitor
$resource = $ikariam['cities']['49447']['resources']['wood'];
//See if we have at least 500 of that resource
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

echo "<pre>"; print_r ($ikariam); die("</pre>");
