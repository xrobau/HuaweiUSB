<?php

namespace HuaweiUSB;

class Login
{
    private API $api;
    private ?string $firstNonce = null;

    public function __construct(API $api)
    {
        $this->api = $api;
    }

    public function getFirstNonce(): string
    {
        if ($this->firstNonce === null) {
            $src = time() . date('-m-d H:i:s');
            $this->firstNonce = hash('sha256', $src);
            print "I used $src to end up with $this->firstNonce\n";
        }
        return $this->firstNonce;
    }

    public function login()
    {
        // Flush session token
        $this->api->getSesTokenInfo(true);
        $xml = "<?xml version='1.0' encoding='UTF-8'?><request><username>%s</username>";
        $xml .= "<firstnonce>%s</firstnonce><mode>1</mode></request>";
        $req = sprintf($xml, $this->api->getUsername(), $this->getFirstNonce());
        $r = $this->api->post('/api/user/challenge_login', ['body' => $req, '__withresp' => true, '__flushtokens' => true]);
        $x = simplexml_load_string($r['body']);
        $salt = (string) $x->salt;
        $servernonce = (string) $x->servernonce;
        $iter = (int) $x->iterations;
        if ($iter !== 100) {
            throw new \Exception("Something wrong with the challenge login resp $r");
        }

        // Here's the magic.
        $authMsg = $this->getFirstNonce() . "," . $servernonce . "," . $servernonce;

        $ctx = hash_init('sha256');
        $saltPassword = hash_pbkdf2('sha256', $this->api->getPassword(), hex2bin($salt), $iter, 0, TRUE);
        $clientKey = hash_hmac('sha256', $saltPassword, "Client Key", TRUE);
        hash_update($ctx, $clientKey);
        $storedkey = hash_final($ctx, TRUE);
        $signature = hash_hmac('sha256', $storedkey, $authMsg, TRUE);

        for ($i = 0; $i < strlen($clientKey); $i++) {
            $clientKey[$i] = $clientKey[$i] ^ $signature[$i];
        }
        $clientproof = bin2hex($clientKey);

        $post = "<?xml version='1.0' encoding='UTF-8'?>" .
            "<request>" .
            "<clientproof>" . $clientproof . "</clientproof>" .
            "<finalnonce>"  . $servernonce . "</finalnonce>" .
            "</request>";

        $r = $this->api->post('/api/user/authentication_login', ['body' => $post, 'debug' => false, '__withresp' => true]);
        // print "auth_login headers: " . json_encode($r['resp']->getHeaders()) . "\n";
        if (!$this->api->isLoggedIn()) {
            throw new \Exception("Am not logged in. What happened with " . $r['body']);
        }
        return true;
    }
}
