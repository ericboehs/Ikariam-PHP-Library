<?
include_once 'config.php';
include_once 'vendors/simple_html_dom.php';
include_once 'library/ikariam.php';


$ikariam['session']['base_url'] = getBaseURL($tld, $world);
if(!checkLogin())
	doLogin($user, $pass);
$ikariam = updateResources();
$ikariam = getIslandIDs();

//echo "<pre>".
//transportFreight($ikariam['current_city_id'],'176','46774','1000','0','0','0','0');

//$json = array_merge($ikariam['cities']['global']['resources'],$ikariam['cities']['46774']['resources']);
//echo json_encode(array_values($json));

//echo json_encode($ikariam);

echo "<pre>"; print_r ($ikariam); die("</pre>");