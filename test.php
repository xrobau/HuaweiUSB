<?php

use HuaweiUSB\API;
use HuaweiUSB\Login;
use HuaweiUSB\Controllers\Device;
use HuaweiUSB\Controllers\SMS;

require 'vendor/autoload.php';

$a = new API("192.168.67.1");
// getDeviceConfig does not need auth
$d = new Device($a);
// var_dump($d->getDeviceConfig());
$a->usingPassword('adminadmin');
if ($a->isLoggedIn()) {
    print "yay, logged in still\n";
} else {
    print "poop, not logged in\n";
    $l = new Login($a);
    $l->login();
    if (!$a->isLoggedIn()) {
        throw new \Exception("Could not log in");
    }
}

$s = new SMS($a);

$delstored = false;

$loops = 10;
while ($loops-- > 0) {
    $stored = $s->getStoredSMSs();
    // print "I found " . count($stored) . " with $loops remaining\n";
    foreach ($stored as $m) {
        print "Message ID " . $m->Index . " was from " . $m->Phone . ", saying '" . $m->Content . "' ";
        if ($delstored) {
            print "Deleting it - Response is " . json_encode($s->delSms($m->Index));
        }
        print "\n";
    }
    usleep(50000);
}

exit;

// Send SMS's like this
$s->sendSms(["+61402111222", "+61402222333"], "Message to multiple");
$s->sendSms("+61402333333", "Message to Single");
