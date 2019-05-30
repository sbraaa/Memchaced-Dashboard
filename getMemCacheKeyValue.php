<?php
if (isset($_POST['key'])) {
	$data = array("error"=>false, "value"=>'');
	$memcached = new Memcached();
	$memcached->addServer("127.0.01",11211);
	$key_value =  $memcached->get($_POST['key']);
	if ($memcached->getResultCode() == Memcached::RES_NOTFOUND) {
		$data['error'] = true;
	} else {
		$data['value'] = $key_value;
	}
	echo json_encode($data);
}
