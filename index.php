<?php
class Simple_memcached_dashboard{
	public $memcache = null;
	public $list     = null;
	public $status   = null;
	public $error    = false;
	public $server   = '';
	public $port     = '';
	private $users   = array();


	function __construct($server = '127.0.0.1',$port = '11211',$users  = array('admin' => 'nimda')){
		session_start();
		$this->users = $users;
		$this->validate_login();
		$this->need_login();
		$this->server = $server;
		$this->port = $port;
		$this->setup();
		$this->dashboard();
	}

	function need_login(){
		if (!$this->is_logged_in()){
			$this->header();
			$this->login_form();
			$this->footer();
			die();
		}
	}

	function login_form(){
		?>
		<div class="row top20">
			<div class="col-md-4 col-md-offset-4">
				<div class="panel panel-default">
					<div class="panel-heading"><h3 class="panel-title"><strong>Sign In </strong></h3></div>
					<div class="panel-body">
						<form class="form-horizontal" action='' method="POST" role="form">
							<?php if($this->error){
								?><div class="alert alert-danger"><?= $this->error; ?></div><?php
							}?>
							<fieldset>
								<div class="form-group">
									<!-- Username -->
									<label class="col-md-3 control-label"  for="username">Username</label>
									<div class="col-xs-6 col-sm-6 col-md-6 col-lg-6">
										<input type="text" id="username" name="username" placeholder="" class="input-xlarge">
									</div>
								</div>

								<div class="form-group">
									<!-- Password-->
									<label class="col-md-3 control-label" for="password">Password</label>
									<div class="col-xs-6 col-sm-6 col-md-6 col-lg-6">
										<input type="password" id="password" name="password" placeholder="" class="input-xlarge">
									</div>
								</div>

								<div class="control-group">
									<!-- Button -->
									<div class="controls">
										<input class="btn btn-success" type="submit" name="submit" value="Submit" />
									</div>
								</div>
							</fieldset>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
    }

    function is_logged_in(){
		return (isset($_SESSION['username']) && $_SESSION['username'] != '');
	}

	function add_user($user_name,$user_pass){
		$this->users[$user_name] = $user_pass;
	}

	function logout(){
		if(isset($_GET['action']) && $_GET['action'] == 'logout') {
			$_SESSION['username'] = '';
			session_destroy();
			header('Location:  ' . $_SERVER['PHP_SELF']);
		}
	}

	function validate_login(){
		$this->logout();
		if(isset($_POST['username']) && isset($_POST['password'])) {
			$user = $_POST['username'];
			$pass = $_POST['password'];
			if ($this->user_exists($user)){
				if($pass == $this->get_user_pass($user)) {
					$_SESSION['username'] = $_POST['username'];
				}else {
					$this->error = 'Wrong Password!';
				}
			}else{
				$this->error = 'User Not Found!';
			}
		}
	}

	function user_exists($user){
		return isset($this->users[$user]);
	}

	function get_user_pass($user){
		if ($this->user_exists($user))
			return $this->users[$user];
		else
			return FALSE;
	}

	function setup(){
		$this->memcached = new Memcached();
		$this->memcached->addServer($this->server,$this->port);
		$list = array();


		$allKeys = $this->memcached->getAllKeys();
		//echo "<pre>"; var_dump($allKeys);
		$this->memcached->getDelayed($allKeys);
		$store = $this->memcached->fetchAll();
		//echo "<pre>"; var_dump($store); exit();

		foreach ($store as $dataKey) {
			//echo "<pre>"; var_dump($dataKey); exit();
			$itemKey = $dataKey['key'];
			$itemValue = $dataKey['value'];
			$type = gettype($itemValue);
			$value = $this->maybe_unserialize($itemValue);
			if (is_object($value)|| is_array($value)){
				$value = is_object($value)? json_decode(json_encode($value), true): $value;
				$value = '<pre class="alert alert-warning">'.print_r($this->array_map_deep( $value,array($this,'maybe_unserialize')),true).'</pre>';
			}
			$list[$itemKey] = array(
				'key'   => $itemKey,
				'value' => $value,
				'type'  => $type
			);
		}
		ksort($list);
		$this->list = $list;
		$tmp_status = $this->memcached->getStats();
		$this->status = $tmp_status[$this->server.":".$this->port];
	}

	function formatBytes($bytes, $precision = 2) {
	    $units = array('B', 'KB', 'MB', 'GB', 'TB');

	    $bytes = max($bytes, 0);
	    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	    $pow = min($pow, count($units) - 1);

	    // Uncomment one of the following alternatives
	    $bytes /= pow(1024, $pow);
	    // $bytes /= (1 << (10 * $pow));

	    return round($bytes, $precision) . ' ' . $units[$pow];
	}

	function array_map_deep($array, $callback) {
		$new = array();
		foreach ((array)$array as $key => $val) {
			if (is_array($val)) {
				$new[$key] = $this->array_map_deep($val, $callback);
			} else {
				$new[$key] = call_user_func($callback, $val);
			}
		}
		return $new;
	}

	function maybe_unserialize( $original ) {
		if ( $this->is_serialized( $original ) ) // don't attempt to unserialize data that wasn't serialized going in
			return @unserialize( $original );
		return $original;
	}

	function is_serialized($value, &$result = null){
		// Bit of a give away this one
		if (!is_string($value)){
			return false;
		}

		// Serialized false, return true. unserialize() returns false on an
		// invalid string or it could return false if the string is serialized
		// false, eliminate that possibility.
		if ($value === 'b:0;'){
			$result = false;
			return true;
		}

		$length	= strlen($value);
		$end	= '';

	 	if (!isset($value[0])) return false;
		switch ($value[0]){
			case 's':
				if ($value[$length - 2] !== '"'){
					return false;
				}
			case 'b':
			case 'i':
			case 'd':
				// This looks odd but it is quicker than isset()ing
				$end .= ';';
			case 'a':
			case 'O':
				$end .= '}';
	 			if ($value[1] !== ':'){
					return false;
				}

				switch ($value[2]){
					case 0:
					case 1:
					case 2:
					case 3:
					case 4:
					case 5:
					case 6:
					case 7:
					case 8:
					case 9:
					break;

					default:
						return false;
				}
			case 'N':
				$end .= ';';
	 			if ($value[$length - 1] !== $end[0]){
					return false;
				}
			break;

			default:
				return false;
		}

		if (($result = @unserialize($value)) === false){
			$result = null;
			return false;
		}
		return true;
	}

	function print_hit_miss_widget(){
		$status = $this->status;
		?>
		<div class="col-xs-12 col-sm-6 col-md-6 col-lg-6">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title">Hit/Miss</h3>
				</div>
				<div class="panel-body">
					<div id="hit_miss_cart" style="height: 250px;"></div>
				</div>
			</div>

			<script type="text/javascript">
			jQuery(document).ready(function(){
				Morris.Donut({
					element: 'hit_miss_cart',
					data: [
						{label: "Hit", value: <?= $status["get_hits"] ?>},
						{label: "Miss", value: <?= $status["get_misses"] ?>},
					],
					colors: ['#5cb85c','#d9534f']
				});
			});
		    </script>
		</div>
		<?php
	}

	function print_memory_widget(){
		$status  = $this->status;
		$MBSize  = (real) $status["limit_maxbytes"]/(1024*1024) ;
		$MBSizeU = number_format((real) $status["bytes"]/(1024*1024),4);
		$MBRead  = number_format((real)$status["bytes_read"]/(1024*1024),4);
		$MBWrite = number_format((real) $status["bytes_written"]/(1024*1024),4);
		?>
		<div class="col-xs-12 col-sm-6 col-md-6 col-lg-6">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title">Memory</h3>
				</div>
				<div class="panel-body">
					<div id="memory_cart" style="height: 235px;"></div>
				</div>
			</div>
			    <script type="text/javascript">

				function MbytesToSize(bytes) {
					if (parseInt(Math.floor(bytes)) > 0) return bytes+' MB';
					if (parseInt(Math.floor(bytes*10)) >0 || parseInt(Math.floor(bytes*100))  > 0) return bytes+' KB';
					if (parseInt(Math.floor(bytes*1000)) > 0) return (bytes * 1000)+' Bytes';
					return bytes;
				}

			    jQuery(document).ready(function(){
					Morris.Bar({
						element: 'memory_cart',
						data: [
							{type: 'total',v: '<?= $MBSize ?>'},
							{type: 'used', v: '<?= $MBSizeU ?>'},
							{type: 'read', v: '<?= $MBRead ?>'},
							{type: 'Sent', v: '<?= $MBWrite ?>'}
						],
						xkey: 'type',
						ykeys: ['v'],
						labels: ['MB'],
						barColors: function (row, series, type) {
							if (type === 'bar') {
								var colors = ['#f0ad4e', '#5cb85c', '#5bc0de', '#d9534f', '#17BDB8'];
								return colors[row.x];
							}else {
								return '#000';
							}
						},
						barRatio: 0.4,
						xLabelAngle: 35,
						hideHover: 'auto',
						hoverCallback: function (index, options, content, row) {
							return "<div class='morris-hover-row-label'>total</div><div class='morris-hover-point' style='color: #000'>"+MbytesToSize(row['v'])+"</div>";
						}
					});
				});
			    </script>
			</div>
		<?php
	}

	function print_status_dump_widget(){
		$status = $this->status;
		echo '<pre>'.print_r($status,true).'</pre>';
	}

	function dashboard(){
		//delete
		if (isset($_GET['del'])) {
			$this->memcached->delete($_GET['del']);
			header("Location: " . $_SERVER['PHP_SELF']);
		}
		//flush
		if (isset($_GET['flush'])) {
			$this->memcached->flush();
			header("Location: " . $_SERVER['PHP_SELF']);
		}
		//set
		if (isset($_GET['set'])) {
			$this->memcached->set($_GET['set'], $_GET['value']);
			header("Location: " . $_SERVER['PHP_SELF']);
		}

		//header
		$this->header();

		?><div class="row" style="margin-top: 60px;"><?php

		//server info
		$this->print_server_info();
		//charts
		$this->print_charts();

		?></div><?php

		//stored data
		$this->stored_data_table();
		//footer
		$this->footer();
	}

	function stored_data_table(){
		?>
		<a name="stored_data">&nbsp;</a>
		<div class="panel panel-default top20">
			<div class="panel-heading">
				<h3 class="panel-title">Stored Keys</h3>
			</div>
			<div class="panel-body">
				<div class="btn-group btn-group-justified">
					<div class="btn-group">
						<a class="btn btn-info" href="<?= $_SERVER['PHP_SELF'] ?>" onclick="">Refresh</a>
					</div>
					<div class="btn-group">
						<a class="btn btn-primary" href="#" onclick="memcachedSet()">Set</a>
					</div>
					<div class="btn-group">
						<a class="btn btn-danger" href="#" onclick="flush()">Flush</a>
					</div>
				</div>
				<div class="table-responsive">
					<table id="stored_keys" class="table table-bordered table-hover table-striped" style="table-layout: fixed;">
						<thead>
							<tr>
								<th class="one_t">key</th>
								<th class="one_h">value</th>
								<th>type</th>
								<th>delete</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach($this->list as $i): ?>
								<tr>
									<td class="one_t"><span class="key_scroll"><?= $i['key'] ?></span></td>
									<td class="one_h"><?php
										if ($i['type'] != 'array' && $i['type'] != 'object') {
											echo $i['value'] ;
										} else {
											echo '<button data-toggle="collapse" data-target="#div_'.$i['key'].'" id="bk_'.$i['key'].'" class="coll_expand_value">expand</button>
											<div id="div_'.$i['key'].'" class="collapse">'.$i['value'].'</div>';
										}

									?></td>
									<td><?= $i['type'] ?></td>
									<td><a class="btn btn-danger" onclick="deleteKey('<?= $i['key'] ?>')" href="#">X</a>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	function print_charts(){

			$this->print_hit_miss_widget();
			$this->print_memory_widget();
	}

	function print_server_info(){
		$status = $this->status;
		//$this->print_status_dump_widget();
		?>
		<div class="col-xs-12 col-sm-6 col-md-6 col-lg-6">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title">Server Info</h3>
				</div>
				<div class="panel-body">
				<?php
					echo "<table class='table'>";
					echo "<tr><td>Memcache version</td><td> ".$status ["version"]."</td></tr>";
					echo "<tr><td>Process id (pid)</td><td>".$status ["pid"]."</td></tr>";
					echo "<tr><td>Server Uptime </td><td>".gmdate("H:i:s", $status["uptime"])."</td></tr>";
					echo "<tr><td>Number of keys since start</td><td>".$status ["total_items"]."</td></tr>";
					echo "<tr><td>Active connections </td><td>".$status ["curr_connections"]."</td></tr>";
					echo "<tr><td>Connections since start</td><td>".$status ["total_connections"]."</td></tr>";
					echo "<tr><td>Retrieval requests </td><td>".$status ["cmd_get"]."</td></tr>";
					echo "<tr><td>Storage requests </td><td>".$status ["cmd_set"]."</td></tr>";

					if ((real)$status ["cmd_get"] != 0)
						$percCacheHit=((real)$status ["get_hits"]/ (real)$status ["cmd_get"] *100);
					else
						$percCacheHit=0;
					$percCacheHit=round($percCacheHit,3);
					$percCacheMiss=100-$percCacheHit;

					echo "<tr><td>Hit requested keys</td><td>".$status ["get_hits"]." ($percCacheHit%)</td></tr>";
					echo "<tr><td>Missed requested keys</td><td>".$status ["get_misses"]." ($percCacheMiss%)</td></tr>";
					echo "<tr><td>Bytes read</td><td>".$this->formatBytes($status['bytes_read'])."</td></tr>";
					echo "<tr><td>Bytes sent</td><td>".$this->formatBytes($status['bytes_written'])."</td></tr>";
					echo "<tr><td>Used memory</td><td>".$this->formatBytes($status['bytes'])."</td></tr>";
					echo "<tr><td>Total memory</td><td>".$this->formatBytes($status["limit_maxbytes"])."</td></tr>";
					echo "<tr><td>Removed keys (to free space)</td><td>".$status ["evictions"]."</td></tr>";
					echo "</table>";
				?>
				</div>
			</div>
		</div>
		<?php
	}

	function header(){
		?><!DOCTYPE html>
		<html lang="">
		<head>
			<meta charset="utf-8">
			<meta http-equiv="X-UA-Compatible" content="IE=edge">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<link href="//cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.2.0/css/bootstrap.min.css" rel="stylesheet">
			<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/morris.js/0.5.1/morris.css">
			<link rel="stylesheet" href="//cdn.datatables.net/plug-ins/a5734b29083/integration/bootstrap/3/dataTables.bootstrap.css">
			<link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/alertify.js/0.3.11/alertify.core.min.css">
			<link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/alertify.js/0.3.11/alertify.default.min.css">
			<link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/alertify.js/0.3.11/alertify.bootstrap.min.css">
			<link rel="stylesheet" href="//stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">
			<script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.0/jquery.min.js"></script>
			<style type="text/css">
			pre {overflow: auto;width: 100%;}
			.key_scroll{overflow: auto;width: 100%;word-wrap: break-word; word-break: break-all;margin: 0;float: left;}
			.one_t{width: 30%;}
			.one_h{width: 50%; word-break: break-all;}
			.top20{margin-top: 30px;}
			.navbar-default { background-color: #428bca;}
			.navbar-default .navbar-brand { color: #FFF;; }
			.navbar-default .navbar-text { color: #FFF; }
			.navbar-default .navbar-nav>li>a { color: #FFF; }
			.table>thead>tr>th, .table>tbody>tr>th, .table>tfoot>tr>th, .table>thead>tr>td, .table>tbody>tr>td, .table>tfoot>tr>td { border-top: 0; border-bottom: 1px solid #ddd; }

			.navbar-default .navbar-brand:hover, .navbar-default .navbar-brand:focus, .navbar-default .navbar-nav>li>a:hover, .navbar-default .navbar-nav>li>a:focus { color: #f70505; background-color: transparent; }

 			.dt-body-center { text-align: center; }

			div.top { overflow: hidden; padding: 5px 0; }
			div.top > div { line-height: 40px; }
			div.dataTables_info { float:left; padding-top: 0px; }
			div.dataTables_length { float:left; margin-left: 20px;}
			div.dataTables_filter { float:right;}
			</style>
		</head>
		<body>
		<nav class="navbar navbar-default navbar-fixed-top" role="navigation">
			<div class="container">
			<!-- Brand and toggle get grouped for better mobile display -->
				<div class="navbar-header">
					<button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1">
						<span class="sr-only">Toggle navigation</span>
						<span class="icon-bar"></span>
						<span class="icon-bar"></span>
						<span class="icon-bar"></span>
					</button>
					<a class="navbar-brand" href="<?= $_SERVER['PHP_SELF'] ?>">Memcached Dashboard</a>
				</div>
			<?php
			if ($this->server != ''){
				?><p class="navbar-text">Server IP: <?= $this->server ?> Port: <?= $this->port ?></p><?php
			}
			if ($this->is_logged_in()){
				?>
				<!-- Collect the nav links, forms, and other content for toggling -->
				<div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
					<ul class="nav navbar-nav navbar-right">

						<li><a href="#" title="Server Info"><i class="fa fa-server fa-lg" aria-hidden="true"></i></a></li>
						<li><a href="#stored_data" title="Keys Info"><i class="fa fa-database fa-lg" aria-hidden="true"></i></a></li>
						<li><a href="<?= $_SERVER['PHP_SELF']; ?>?action=logout" title="Logout"><i class="fa fa-sign-out fa-lg" aria-hidden="true"></i></a></li></li>

					</ul>
				</div><!-- /.navbar-collapse -->
				<?php
			}?>
		  </div><!-- /.container-fluid -->
		</nav>
		<div class="container top20">
    	<?php
	}

	function footer(){
		?>
		<script src="//cdnjs.cloudflare.com/ajax/libs/raphael/2.1.0/raphael-min.js"></script>
		<script src="https://cdnjs.cloudflare.com/ajax/libs/morris.js/0.5.1/morris.min.js"></script>
		<script type="text/javascript" src="//cdn.datatables.net/1.10.2/js/jquery.dataTables.min.js"></script>
		<script type="text/javascript" src="//cdn.datatables.net/plug-ins/a5734b29083/integration/bootstrap/3/dataTables.bootstrap.js"></script>
		<script type="text/javascript" src="//cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.2.0/js/bootstrap.min.js"></script>
		<script type="text/javascript" src="//cdnjs.cloudflare.com/ajax/libs/alertify.js/0.3.11/alertify.min.js"></script>
		<script type="text/javascript">
			jQuery(document).ready(function(){
				$("#stored_keys").dataTable({
					"bFilter":true,
					"bSort":true,
					"dom": '<"top"ilf>rt<"bottom"p><"clear">',
					"pageLength": 50,
					columnDefs: [
						{  targets: 2, className: 'dt-body-center' },
						{  targets: 3, className: 'dt-body-center' }
					]

				});

				jQuery(".coll_expand_value").on('click', function(){
					var key = this.id.replace("bk_","");
					if (jQuery("#div_"+key).hasClass("in") == true) {
						jQuery("#bk_"+key).text("expand");
					} else {
						jQuery("#bk_"+key).text("collapse");
					}
				});

			});

			function memcachedSet() {
				alertify.prompt("Set Key", function (e, key) {
					// key is the input text
					if (e) {
						alertify.prompt("Set Value", function (e, value) {
							// value is the input text
							if (e) {
								window.location.href = "<?= $_SERVER['PHP_SELF'] ?>?set="+ key +"&value=" + value;
							} else {
							// user clicked "cancel"
							}
						}, "Some Value");
					} else {
						// user clicked "cancel"
					}
				}, "SomeKey");
			}
			function deleteKey(key){
				alertify.confirm("Are you sure?", function (e) {
					if (e) {
						window.location.href = "<?= $_SERVER['PHP_SELF'] ?>?del="+key;
					} else {
					// user clicked "cancel"
					}
				});
			}
			function flush(){
				alertify.confirm("Are you sure?", function (e) {
					if (e) {
						window.location.href = "<?= $_SERVER['PHP_SELF'] ?>?flush=1";
					} else {
					// user clicked "cancel"
					}
				});
			}
		</script>
		</body>
		</html>
		<?php
	}
}//end class
new Simple_memcached_dashboard();
