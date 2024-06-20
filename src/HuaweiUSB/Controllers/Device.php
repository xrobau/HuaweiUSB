<?php

namespace HuaweiUSB\Controllers;

use HuaweiUSB\API;

class Device
{
    private API $api;

    public function __construct(API $api)
    {
        $this->api = $api;
    }

    public function getDeviceConfig()
    {
        // Does not need to be logged in
        $c = $this->api->getClient()->request('GET', '/config/global/config.xml');
        $x = simplexml_load_string($c->getBody());
        return $x;
    }

    public function reboot()
    {
        if (!$this->api->isLoggedIn()) {
            throw new \Exception("Not logged in, can't reboot");
        }
        $req = '<?xml version="1.0" encoding="UTF-8"?><request><Control>1</Control></request>';
        $r = $this->api->post('/api/device/control', ['body' => $req,]);
        print "I sent stuff to device control, It told me '$r'\n";
        exit;
    }

    /**
     * The backup is encrypted, don't know how to decrypt it yet.
     *
     * @return void
     */
    public function backup()
    {
        $req = '<?xml version="1.0" encoding="UTF-8"?><request><Control>3</Control></request>';
        $r = $this->api->post('/api/device/control', ['body' => $req,]);
        $bak = $this->api->get('/nvram.bak', []);
        file_put_contents("nvram.bak", $bak);
        print "Saved to nvram.bak\n";
    }

    /**
     * Apparently setting it to 2 enables Telnet. Doesn't work for me.
     *
     * @param integer $mode
     * @return void
     */
    public function setMode(int $mode = 2)
    {
        $req = '<?xml version="1.0" encoding="UTF-8"?><request><mode>' . $mode . '</mode></request>';
        $r = $this->api->post('/api/device/mode', ['body' => $req,]);
        print "I sent stuff to device control, It told me '$r'\n";
    }
}
