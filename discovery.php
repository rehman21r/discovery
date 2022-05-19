<?
  include 'functions.php';
  checkhttplogin ();
  $pdo = pdoconnection();
  // error_reporting(-1);

  if(isset($_GET['del'])){

    $sip_val = intval($_GET['del']);

    if($sip_val < 1)
      die('error-3');

    $path = '/var/www/html/crestron';
    $dir = array_diff(scandir($path), array('.', '..'));

    foreach ($dir as $d) {

      $dd = str_replace(".txt", "", $d);
      $dd = explode("_", $dd);

      if ($sip_val == $dd[1]) {
        unlink('/var/www/html/crestron/'.$d);
      }

    }

    $db = pdoconnection();

    $q = $db->prepare("SELECT user_ID FROM rel_user_sip WHERE sip_name = :name");
    $q->bindParam("name", $sip_val);
    $q->execute();
    $user_id = $q->fetchColumn();

    deleteuser($user_id);

    $q = $db->prepare("DELETE FROM `ast_config` WHERE `filename` LIKE 'sip.conf' AND `category` = :category");
    $q->bindParam("category", $sip_val);
    $r = $q->execute();

    $q = $db->prepare("DELETE FROM `device` WHERE `tech_name` = :tech_name");
    $q->bindParam("tech_name", $sip_val);
    $r = $q->execute();

    $q = $db->prepare("DELETE FROM `crestron_setup_devices` WHERE `extension` = :extension");
    $q->bindParam("extension", $sip_val);
    $r = $q->execute();

    shell_exec('/etc/asterisk/updatesip.sh');
    shell_exec("/usr/sbin/asterisk -rx 'pjsip reload'");        

    echo "Selected device has been deleted successfully!";

    die();
  }

  require __DIR__ . '/phpseclib2/vendor/autoload.php';

  use phpseclib\Net\SSH2;

  $file = file_get_contents('/etc/asterisk/pjsip.conf');

  if(strpos($file, ';#include "crestronexport.conf"')){
    $mode = 'disable';
  } else {
    $mode = 'enable';
  }
  
  include('../api_part.php');

  $crestron_setup_devices = $pdo->query("SELECT * FROm crestron_setup_devices")->fetchAll(PDO::FETCH_ASSOC);

  $active_devices = $devices;

  // var_dump($active_devices); die();

  foreach ($active_devices as $active_key => $active_device) {

    if(empty(trim($active_device)) || strpos($active_device, "====") !== FALSE)

      continue;

    if(strpos($active_device, "Objects found:")){
      $active_device = explode("Objects found:", $active_device);
      $active_device = $active_device[0];
    }

    $actdevice[] = array_filter(explode(" ", trim($active_device)));

  }

  $devices = $newest_devices;

  exec('ip -s -s neigh flush all');
  
  function get_mac_from_ip($ip, $second_try=false){
    global $pdo;
    $command_type = $pdo->query("SELECT * FROM command_type")->fetch(PDO::FETCH_ASSOC);
    $commands = "ipconfig \r \n \r \n";
    if($second_try){

      $socket = fsockopen($ip, 23, $errno, $errstr);
      
      if($socket) {

        fputs($socket, $commands."bye \r \n");

        $buffer = fgets($socket, 4096);

        $i = 1;
        while(!feof($socket)) 
        { 
          $buffer .=fgets($socket, 4096); 
          if($i > 20){
            
            break;
          }
          $i++;
        }

        if(strpos($buffer, 'MAC Address') === FALSE)
          return null;

        $mac = explode("MAC Address", $buffer);
        $mac = explode(":", $mac[1]);
        $mac = explode("\n", $mac[1]);
        $mac = trim($mac[0]);
        return str_replace(".", "", $mac);

      } else { 

        $ssh = new SSH2($ip);

        if(!$ssh->login($command_type['ssh_username'], $command_type['ssh_password'])){
          return null;
        }

        $buffer = $ssh->exec($commands);
        $buffer .= $ssh->exec("bye \r \n");

        if(strpos($buffer, 'MAC Address') === FALSE)
          return null;

        $mac = explode("MAC Address", $buffer);
        $mac = explode(":", $mac[1]);
        $mac = explode("\n", $mac[1]);
        $mac = trim($mac[0]);
        return str_replace(".", "", $mac);

      }

    }

    $mac = shell_exec('arp -a '.$ip);
    $mac = explode('at ', $mac)[1];
    $mac = trim(explode('[ether]', $mac)[0]);
    $mac = preg_replace('/:+/', '', $mac);
    $mac = preg_replace('/ +/', '', $mac);

    if($mac != FALSE && !empty($mac))
      return $mac;

    if($second_try === false)
      return get_mac_from_ip($ip, true);

    return false;

  }

  $success = "";

  
  // if(isset($_GET['configure']) && !empty($_GET['configure'])){

  //   if(!isset($_GET['panel_name']) || empty($_GET['panel_name'])){
  //     echo 'error-4';
  //     die();
  //   }

  //   $panel_name = $_GET['panel_name'];

  //   $mac_address = $_GET['configure'];

  //   if(isset($_GET['sip']))
  //     $sip = $_GET['sip'];
  //   else
  //     $sip = '';

  //     telnet_to_ip_with_mac($mac_address, $panel_name, $sip);

  //     die;

  // } /* end of if(isset($_GET['configure']) && !empty($_GET['configure'])) */
  // } /* end of if($mode == 'disable') */

  if(isset($_POST['ip']) && !empty($_POST['ip'])){

    $ip = $_POST['ip'];
    $panel_name = $_POST['panel_name'];

    if(empty($ip) || empty($panel_name)){
      echo 'error::Please fill out all required fields.';
      die();
    }

    if(filter_var($ip, FILTER_VALIDATE_IP) === false)
    {
      echo 'error::Invalid IP, please enter a valid IP.';
      die();
    }

    global $pdo;
    
    global $devices;
    $already_exist = 0;
    foreach ($devices as $d) {
      if($ip == $d['IP'])
        $already_exist++;
    }

    if($already_exist > 0){
      echo 'error::This IP already configured: '.$ip;
      die();
    } else {
      $query = $pdo->prepare("SELECT COUNT(*) FROM crestron_setup_devices WHERE ip : ip");
      $query->bindParam("ip", $ip);
      $query->execute();
      if($query->fetchColumn() > 0) {
        echo 'error::This IP '.$ip.' already exist in the database.';
        exit();
      }
    }

    $mac = get_mac_from_ip($ip);

    if($mac === false){
      echo 'error::No mac address found for this IP: '.$ip;
      die();
    }

    $sip = 99;

    while (1==1) {
      $sip++;
      if($pdo->query("SELECT COUNT(*) FROM sip WHERE name = '$sip'")->fetchColumn() < 1)
          break;
    }

    telnet_to_ip_with_mac($mac, $panel_name, $sip, $ip);

    die();

  }

  function telnet_to_ip_with_mac($mac, $panel_name, $sip, $ip){

    global $devices;
    global $pdo;

    $command_type = $pdo->query("SELECT * FROM command_type")->fetch(PDO::FETCH_ASSOC);

    $socket = fsockopen($ip, 23, $errno, $errstr);
    $t = '1';
    if($socket) {} else { 

      if(isset($command_type['type']) && $command_type['type'] == '2'){ // it means ssh protocal will be used.
        $ssh = new SSH2($ip);
        if(!$ssh->login($command_type['ssh_username'], $command_type['ssh_password']))  
        {
          echo "error-5"; die();
        }

        $rr = $ssh->exec('SIPINFO');

        if($rr == false || empty($rr)) {
          echo "error-5"; die();
        }

        $t = '2';

      } else {
        echo "error-3"; //"There is some error while connecting to the device (IP: $ip)"
        die;
      }
      
    } 
    
    $r = create_user_with_mac_and_panel($mac, $panel_name, $sip);

    if(!$r){
      echo 'Error creating/updating user'; die();
    }

    telnet_to_ip($t, $mac, $ip, $sip);


    echo "There is some error.";
    die; 
  } /* end of function */

  function add_entry_to_db($ip, $sip, $mac){
    global $pdo;
    $query = $pdo->prepare("INSERT INTO crestron_setup_devices (ip, extension, mac) VALUES(:ip, :extension, :mac)");
    $query->bindParam("ip", $ip);
    $query->bindParam("extension", $sip);
    $query->bindParam("mac", $mac);
    if(!$query->execute()){
      die($query->errorInfo());
    }
  }

  function telnet_to_ip($t, $mac, $ip, $sip){
    global $pdo;
    $pdo = pdoconnection();
    $command_type = $pdo->query("SELECT * FROM command_type")->fetch(PDO::FETCH_ASSOC);

    $path = '/var/www/html/crestron';
    $dir = array_diff(scandir($path), array('.', '..'));

    foreach ($dir as $d) {

      $dd = str_replace(".txt", "", $d);
      $dd = explode("_", $dd);

      $mac_address = $dd[2];

      if($mac == trim($mac_address)){

      // if(strpos(strtolower($dev), strtolower($mac_address))){

        $f = $path."/".$d;
        
        try {
          $f = file_get_contents($f);
        }
        catch (Exception $e) {
            echo 'error-2'; //"There is some error while getting commands list."
            die;
        }

        if($t == '1'){

          $commands = $f." \r \n bye \r \n \r \n";

          $socket = fsockopen($ip, 23, $errno, $errstr);
          
          if($socket) {} else { 
            echo "3"; //"There is some error while connecting to the device (IP: $ip)"
            die;
          } 
          
          fputs($socket, $commands);

          $buffer = fgets($socket, 4096);

          $i = 1;
          while(!feof($socket)) 
          { 
            $buffer .=fgets($socket, 4096); 
            if($i > 45){
              
              break;
            }
            $i++;
          }

          // var_dump($buffer); exit();

          if($buffer){
            fclose($socket);

            shell_exec('/etc/asterisk/updatesip.sh');
            shell_exec("/usr/sbin/asterisk -rx 'pjsip reload'"); 

            echo "Device: $mac <br> has been configured successfully!";
            add_entry_to_db($ip, $sip, $mac);
            die; 
          } else {
            fclose($socket);
            echo "error-3"; //"There is some error while sending commands to the device (IP: $ip)"
            die; 
          }

        } else {

          $commands = explode("\n", $f);

          $ssh = new SSH2($ip);
          if(!$ssh->login($command_type['ssh_username'], $command_type['ssh_password']))
          {
            echo "SSH connection error."; die();
          }
          $rr = $ssh->exec($f." \r \n bye \r \n \r \n");
          // foreach ($commands as $key => $command) {
          //   $rr = $ssh->exec($command);
          // }

          shell_exec('/etc/asterisk/updatesip.sh');
          shell_exec("/usr/sbin/asterisk -rx 'pjsip reload'"); 

          echo "Device: $mac <br> has been configured successfully!"; 

          add_entry_to_db($ip, $sip, $mac);

          die();

        }        
        
        break;

      }

    }


  } /* end of the function */

?>

<?php 

  $sip_custom = 99;

  while (1==1) {
    $sip_custom++;
    if($pdo->query("SELECT COUNT(*) FROM sip WHERE name = '$sip_custom'")->fetchColumn() < 1)
        break;
  }

?>

<div>
  <button class="btn btn-info" onclick="show_hide('configure_new_device');" style="float: right;margin-bottom: 10px;padding: 10px;">
    Add Crestron Panel
  </button>
  <form method="POST" onsubmit="event.preventDefault();configure_new_device();">
    <table class="no-display" id="configure_new_device" style="margin: 0 auto; margin-top: 10px;width: 50%;">
      <tr>
        <td width="200">
          <label for="extension">Extension</label>
        </td>
        <td>
          <input type="text" name="extension" id="extension" class="form-control" value="<?php echo $sip_custom; ?>" disabled style="width: 100%;">
        </td>
      </tr>
      <tr>
      <tr>
        <td width="200">
          <label for="panel_name">Panel Name</label>
        </td>
        <td>
          <input type="text" name="panel_name" id="panel_name" class="form-control" value="" required style="width: 100%;">
        </td>
      </tr>
      <tr>
        <td width="200">
          <label for="ip">Device IP</label>
        </td>
        <td>
          <input type="text" name="ip" id="ip" class="form-control" value="" required style="width: 100%;">
        </td>
      </tr>
      <tr>
        <td width="200"></td>
        <td>
          <button type="submit" class="btn-info" name="configure_new_device_btn" id="configure_new_device_btn" style="float: right;">Save</button>
        </td>
        
      </tr>
    </table>
  </form>
</div>

<h5 style="margin-bottom: 10px;">Active Devices:</h5>
<table border="0">
  <tbody>
    <tr>
      <th style="width: 50px;"><b>#</b></th>
      <th>Panel Name</th>
      <th>Extension</th>
      <th>IP</th>
      <th>Mac</th>
      <th>Device</th>
      <th>Status</th>
      <th>Action</th>
    </tr>
    <?php
  	$i = 1;

  	foreach ($devices as $device_key => $device) { 

        ?>
        <tr>
          <td><?=$i?></td>
          <td><?php echo $device['User']; ?></td>
          <td><?php echo $device['Extension']; ?></td>
          <td><?php echo $device['IP']; ?></td>
          <td><?php echo $device['Mac']; ?></td>
          <td><?php echo $device['Device']; ?></td>
          <td style="color: <?php if(empty($device['User'])) echo 'red'; else echo 'green'; ?>;"><?php if(empty($device['User'])) echo 'In-Progress'; else echo 'Configured!' ?></td>
          <td>
            <?php if(!empty($device['User'])){?>
            <a href="javascript:void(0);" disabled style="float: right;padding:0.375rem 0.75rem;" onclick="del('<?php echo $device['Extension']; ?>', $(this));">Delete</a>

            <?php } ?>
          </td>
        </tr>
      	<?php 

      	$i++; 
	 } ?>

   <?php

   foreach ($crestron_setup_devices as $crestron_setup_device) {
    
    $found = false;

    foreach ($devices as $device) {
      if($crestron_setup_device['extension'] == $device['Extension'])
        $found = true;
    }

    if($found == false){ 

      $callerid = $pdo->query("SELECT callerid FROM `sip` WHERE `name` = '".$crestron_setup_device['extension']."'")->fetchColumn();
      if($callerid){
        $callerid = trim(str_replace('<'.$crestron_setup_device['extension'].'>', '', $callerid));
        $callerid = trim(str_replace('"', "", $callerid));
        $callerid = trim(str_replace('"', "", $callerid));
      }

      ?>

      <tr>
        <td><?php echo $i; ?></td>
        <td><?php echo $callerid; ?></td>
        <td><?php echo $crestron_setup_device['extension']; ?></td>
        <td><?php echo $crestron_setup_device['ip']; ?></td>
        <td><?php echo $crestron_setup_device['mac']; ?></td>
        <td></td>
        <td style="color: red;">In-Progress</td>
        <td>
          
        </td>
      </tr>


    <?php } 


   }

   ?>


		<?php if($i == 1){ ?>
  		<tr>
  			<td colspan="8">No Record found.</td>
  		</tr>
  		<?php } ?>

  </tbody>
</table>

<h5 style="margin-bottom: 10px;">Contact Status:</h5>
<table border="0">
  <tbody>
    <tr>
      <th style="width: 50px;"><b>#</b></th>
      <th>Contact</th>
      <th>Hash</th>
      <th>Status</th>
      <th>RTT(ms)</th>
    </tr>
    <?php
    $i = 1;
    foreach($actdevice as $k => $active_device){
        foreach ($devices as $device_key => $device) { 
          if(strpos($active_device[0], $device["IP"]) !== FALSE){
            	$actdevice[$k] = array_values($actdevice[$k]);
        ?>
        <tr>
          <td><?=$i?></td>
          <td><?php echo $actdevice[$k][0]; ?></td>
          <td><?php echo $actdevice[$k][1]; ?></td>
          <td><?php echo $actdevice[$k][2]; ?></td>
          <td><?php echo $actdevice[$k][3]; ?></td>
        </tr>
        <?php 

        $i++; 
          }
        }
    }
    if($i == 1){ ?>
      <tr>
        <td colspan="5">No Record found.</td>
      </tr>
      <?php } ?>

  </tbody>
</table>

