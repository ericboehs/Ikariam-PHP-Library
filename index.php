<?
include_once 'config.php';
include_once 'vendors/simple_html_dom.php';
include_once 'library/ikariam.php';

$ikariam['session']['base_url'] = getBaseURL($tld, $world);
if(!checkLogin())
	doLogin($user, $pass);
$ikariam = updateResources();
$ikariam = getIslandIDs();

//echo json_encode($ikariam);

echo "<pre>"; print_r ($ikariam); die("</pre>");
