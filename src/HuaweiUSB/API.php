<?php

namespace HuaweiUSB;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

class API
{
    private string $dongleip;
    private Client $guzclient;
    private string $username = "admin";
    private string $password = "admin";
    private string $sesinfo = "";
    private array $tokinfo = [];
    private array $tokmap = [];
    private string $cookie = "";
    private array $sestok = [];
    private string $sesstokfile = "/tmp/sesstok.json";

    public function __construct(string $dongleip = "192.168.8.1")
    {
        $this->dongleip = $dongleip;
        $this->guzclient = new Client(['base_uri' => 'http://' . $dongleip, 'debug' => false]);
    }

    public function usingPassword(string $password)
    {
        $this->password = $password;
        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getClient(): Client
    {
        return $this->guzclient;
    }

    public function loadSessTok(bool $force = false)
    {
        if ($force) {
            $this->sestok = [];
        }
        if (empty($this->sestok)) {
            if (file_exists($this->sesstokfile)) {
                $j = json_decode(file_get_contents($this->sesstokfile), true);
                if (!empty($j[$this->dongleip])) {
                    $this->sestok = $j[$this->dongleip];
                    $this->sesinfo = $this->sestok['SesInfo'];
                    $this->cookie = $this->sestok['Cookie'];
                    $this->tokinfo = $this->sestok['TokInfo'];
                    $this->tokmap = [];
                    foreach ($this->tokinfo as $t) {
                        $this->tokmap[$t] = true;
                    }
                }
            }
        }
    }

    /**
     * @param string $tokinfo 
     * @return bool If something was added
     */
    public function addNewTokInfo(string $token, string $reason)
    {
        // We keep them in the order they're returned, just in case anything cares.
        if (empty($this->tokmap[$token])) {
            $this->tokmap[$token] = true;
            $this->tokinfo[] = $token;
            return true;
        }
        return false;
    }

    public function getNextTokInfo($consumelast = false): string
    {
        $c = count($this->tokinfo);
        // If there's none, return an empty string
        if ($c < 1) {
            return "";
        }
        // If can consume a token, return that.
        if ($c > 1 || $consumelast) {
            $tok = array_shift($this->tokinfo);
            unset($this->tokmap[$tok]);
            return $tok;
        }
        // Otherwise just return the one remaining, but don't consume it.
        return array_keys($this->tokmap)[0];
    }

    public function flushSessTokens()
    {
        $this->sesinfo = "";
        $this->tokinfo = [];
        $this->tokmap = [];
        $this->cookie = "";
        $this->saveSessTok();
    }

    public function saveSessTok()
    {
        if (file_exists($this->sesstokfile)) {
            $j = json_decode(file_get_contents($this->sesstokfile), true);
        } else {
            $j = [];
        }
        $j[$this->dongleip] = ["SesInfo" => $this->sesinfo, "TokInfo" => $this->tokinfo, "Cookie" => $this->cookie];
        file_put_contents($this->sesstokfile, json_encode($j));
    }

    public function getSesTokenInfo(bool $refresh = false)
    {
        $this->loadSessTok();
        if (empty($this->sestok) || $refresh) {
            $resp = $this->getClient()->request('GET', '/api/webserver/SesTokInfo');
            $xml = simplexml_load_string((string) $resp->getBody());
            $this->sestok = array($xml);
            $this->sesinfo = (string) $xml->SesInfo;
            $this->cookie = "SessionID=" . $this->sesinfo;
            $this->tokmap = [];
            $this->tokinfo = [];
            $this->addNewTokInfo((string) $xml->TokInfo, "getSesTokInfo empty or refresh");
            $this->saveSessTok();
        }
        return ["SesInfo" => $this->sesinfo, "Cookie" => $this->cookie];
    }

    public function updateReqVerifTok(string $tok)
    {
        $this->tokinfo = $tok;
    }

    public function updateSessionIDCookie(string $c)
    {
        $this->sesinfo = $c;
    }

    public function setRequestHeaders(array $options, string $info)
    {
        if (empty($options['headers'])) {
            $options['headers'] = [];
        }
        $sti = $this->getSesTokenInfo();
        $tokinfo = $this->getNextTokInfo();
        $h = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
            'Connection' => 'Keep-alive',
            'Cookie' => $sti['Cookie'],
            '__RequestVerificationToken' => $tokinfo,
        ];
        foreach ($h as $k => $v) {
            $options['headers'][$k] = $v;
        }
        // print "Using rvt '$tokinfo' for $info\n";
        return $options;
    }

    public function post(string $path, array $options, string $secondattempt = "")
    {
        $c = $this->getClient();
        $options = $this->setRequestHeaders($options, "Post to $path");
        $withresp = $options['__withresp'] ?? false;
        $resp = $c->request('POST', $path, $options);
        $b = (string) $resp->getBody();
        $check = $this->processIncomingHeaders($resp, $path, $options);
        if ($check !== true) {
            return $this->post($path, $options, $check);
        }
        if ($withresp) {
            return ["resp" => $resp, "body" => $b];
        } // else
        return $b;
    }

    public function get(string $path, array $options, string $secondattempt = "")
    {
        $c = $this->getClient();
        $options = $this->setRequestHeaders($options, "Get from $path");
        $withresp = $options['__withresp'] ?? false;
        $resp = $c->request('GET', $path, $options);
        $b = (string) $resp->getBody();
        $check = $this->processIncomingHeaders($resp, $path, $options);
        if ($check !== true) {
            return $this->get($path, $options, $check);
        }
        if ($withresp) {
            return ["resp" => $resp, "body" => $b];
        } // else
        return $b;
    }

    public function isLoggedIn(): bool
    {
        $state = $this->getLoginState();
        return ($state->Username == "admin");
    }

    public function getLoginState()
    {
        $opts = ['debug' => false];
        $resp = $this->get('/api/user/state-login', $opts);
        $x = simplexml_load_string($resp);
        return $x;
    }

    public function processIncomingHeaders(ResponseInterface $resp, string $path, array $options)
    {
        $body = (string) $resp->getBody();
        if (strpos($body, '<error>') !== false) {
            $x = simplexml_load_string($body);
            if (!property_exists($x, 'code')) {
                throw new \Exception("Found <error> in '$body', but no code value");
            }
            if (count($this->tokinfo) < 2) {
                throw new \Exception("Not retrying with count of " . count($this->tokinfo));
            }
            // Something to do with token mismatches
            if ($x->code == 125003) {
                return "Error 125003";
            }
            if ($x->code == 125002) {
                return "Error 125002";
            }
            throw new \Exception("Unknown Error from '$body'");
        }
        $saveneeded = false;
        // This is used by challenge_login
        if (!empty($options['__flushtokens'])) {
            $this->tokmap = [];
            $this->tokinfo = [];
        }
        $headers = [
            '__RequestVerificationTokenone',
            '__RequestVerificationTokentwo',
            '__RequestVerificationToken',
        ];
        foreach ($headers as $h) {
            $hc = $resp->getHeader($h);
            if (!empty($hc) && $h === '__RequestVerificationTokenone') {
                // We've been given a bunch of tokens to use. Flush anything
                // that's remaining.
                $this->tokmap = [];
                $this->tokinfo = [];
            }
            foreach ($hc as $tokval) {
                $sections = explode('#', $tokval);
                foreach ($sections as $tok) {
                    if ($this->addNewTokInfo($tok, "ProcessIncoming via $path")) {
                        // print "I added a new token of $tok from $h via $path\n";
                        $saveneeded = true;
                    } else {
                        // print "Token $tok already existed from $h via $path\n";
                    }
                }
            }
        }
        $setcookies = $resp->getHeader('Set-Cookie');
        foreach ($setcookies as $s) {
            $chunks = explode(' ', $s);
            if (strpos($chunks[0], 'SessionID=') === 0) {
                $sid = trim($chunks[0], ';');
                if ($this->cookie !== $sid) {
                    $this->cookie = $sid;
                    $this->sesinfo = str_replace('SessionID=', '', $sid);
                    $saveneeded = true;
                }
            }
        }
        if ($saveneeded) {
            $this->saveSessTok();
        }
        return true;
    }
}
