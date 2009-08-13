<?
include_once 'config.php';
include_once 'vendors/simple_html_dom.php';
include_once 'library/ikariam.php';

$ikariam['session']['base_url'] = getBaseURL($tld, $world);

$ikariam = updateResources();
if(!$ikariam['session']['logged_in'])
	doLogin($user, $pass);

$ikariam = getIslandIDs();

//echo "<pre>".
//transportFreight($ikariam['current_city_id'],'123','12345','1000','0','0','0','0');

//$json[] = $ikariam['cities']['names']['12345'];

//$json = array_merge($json, $ikariam['cities']['global']['resources'],$ikariam['cities']['12345']['resources']);
//echo json_encode(array_values($json));

//echo json_encode($ikariam);

//Town name is case sensitive; you can use your town's id instead if you want
//upgradeBuilding('Your Town Name','Name of Building');
echo "<pre>"; print_r ($ikariam); die("</pre>");
