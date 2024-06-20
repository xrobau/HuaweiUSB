<?php

namespace HuaweiUSB\Controllers;

use HuaweiUSB\Models\SMSMessage;
use HuaweiUSB\API;

class SMS
{
    private API $api;

    public function __construct(API $api)
    {
        $this->api = $api;
    }

    public function getStoredSMSs($pageno = 1, $pagecount = 20): array
    {
        $post = '<?xml version="1.0" encoding="UTF-8"?><request><PageIndex>' . $pageno . '</PageIndex><ReadCount>' . $pagecount . '</ReadCount><BoxType>1</BoxType><SortType>0</SortType><Ascending>0</Ascending><UnreadPreferred>0</UnreadPreferred></request>';
        $r = $this->api->post("/api/sms/sms-list", ['body' => $post, 'debug' => false]);
        // print "Asked about sms list, using '$post', received '$r'\n";
        $x = simplexml_load_string($r);
        if (property_exists($x, 'code')) {
            // This should never happen, as api->post is meant to retry
            print "I have an error of " . $x->code . " from $r\n";
            exit;
        }
        if (!property_exists($x, 'Messages')) {
            print "Not sure what's happening, didn't get Messages back from $r\n";
            exit;
        }
        $count = $x->Count;
        if ($count == 0) {
            return [];
        }
        $retarr = [];
        foreach ($x->Messages->Message as $k => $v) {
            $s = new SMSMessage((array) $v);
            $retarr[] = $s;
        }
        return $retarr;
    }

    public function sendSms($to, $message)
    {
        if (!is_array($to)) {
            $dests = [$to];
        } else {
            $dests = $to;
        }
        $post = '<?xml version="1.0" encoding="UTF-8"?><request><Index>-1</Index><Phones>';
        foreach ($dests as $num) {
            $post .= "<Phone>$num</Phone>";
        }
        $post .= "</Phones><Sca></Sca><Content>%s</Content><Length>%d</Length><Reserved>1</Reserved>";
        $post .= "<Date>" . date('Y-m-d H:i:s') . "</Date></request>";
        $req = sprintf($post, $message, strlen($message));
        $body = $this->api->post("/api/sms/send-sms", ["body" => $req]);
        return $body;
    }

    public function delSms($smsid)
    {
        $post = '<?xml version: "1.0" encoding="UTF-8"?><request><Index>' . $smsid . '</Index></request>';
        return $this->api->post("/api/sms/delete-sms", ["body" => $post]);
    }
}
