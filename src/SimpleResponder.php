<?php
namespace SharkyDog\mDNS;
use React\Dns\Model\Record;
use React\Dns\Model\Message;
use React\EventLoop\Loop;

class SimpleResponder {
  private static $_rrfactory;
  private $_records = [];
  private $_socket;
  private $_queue = [];
  private $_send_ms = 0;
  private $_send_timer;

  public function addRecordIPv4(string $name, string $addr, int $ttl=120) {
    $this->addRecord($this->_rfy('A', $name, $addr, $ttl));
  }

  public function addRecordIPv6(string $name, string $addr, int $ttl=120) {
    $this->addRecord($this->_rfy('AAAA', $name, $addr, $ttl));
  }

  public function addRecord(Record $record) {
    $record->name = strtolower($record->name);
    $tlc = substr($record->name,0,3);

    if(!isset($this->_records[$tlc])) {
      $this->_records[$tlc] = [];
    }
    if(!isset($this->_records[$tlc][$record->type])) {
      $this->_records[$tlc][$record->type] = [];
    }

    $this->_records[$tlc][$record->type][] = $record;
  }

  public function delRecord(string $name, int $type): bool {
    $name = strtolower($name);
    $tlc = substr($name,0,3);
    $found = false;

    if(!isset($this->_records[$tlc])) {
      return false;
    }
    if(!isset($this->_records[$tlc][$type])) {
      return false;
    }

    foreach($this->_records[$tlc][$type] as $key => $rr) {
      if($rr->name != $name) continue;
      unset($this->_records[$tlc][$type][$key]);
      $this->_records[$tlc][$type] = array_values($this->_records[$tlc][$type]);
      $found = true;
      break;
    }

    if(!$found) {
      return false;
    }

    if(empty($this->_records[$tlc][$type])) {
      unset($this->_records[$tlc][$type]);
    }
    if(empty($this->_records[$tlc])) {
      unset($this->_records[$tlc]);
    }

    return true;
  }

  public function addService(string $type, string $instance, ?int $ttl=null, ?string $target=null, int $srvport=0, string ...$txts) {
    $ttl = $this->_rfy()->DefaultTTL($ttl);

    $svcname = $type.'.local';
    $svctrgt = $instance.'.'.$svcname;
    $this->addRecord($this->_rfy('PTR', $svcname, $svctrgt, $ttl));

    $svcnamelc = strtolower($svcname);
    $rrsptr = $this->_rr('_services._dns-sd._udp.local', Message::TYPE_PTR);
    $found = false;

    foreach($rrsptr as $rrptr) {
      if($rrptr->data == $svcnamelc) {
        $found = true;
        break;
      }
    }
    if(!$found) {
      $this->addRecord($this->_rfy('PTR', '_services._dns-sd._udp.local', $svcname, $ttl));
    }

    if(!$target) {
      return;
    }

    if(!($this->_rr($svctrgt, Message::TYPE_SRV)[0] ?? null)) {
      $this->addRecord($this->_rfy('SRV', $svctrgt, 0, 0, $srvport, $target, $ttl));
    }

    if(!($this->_rr($svctrgt, Message::TYPE_TXT)[0] ?? null)) {
      $this->addRecord($this->_rfy('TXT', $svctrgt, $ttl, ...$txts));
    }
  }

  public function addReverseIPv4(string $addr, string $name, int $ttl=120) {
    if(($iplong = ip2long($addr)) === false) {
      throw new \Exception('Invalid IPv4 address');
    }
    $ptrname = inet_ntop(pack('V',$iplong)).'.in-addr.arpa';
    $this->addRecord($this->_rfy('PTR', $ptrname, $name, $ttl));
  }

  public function delReverseIPv4(string $addr): bool {
    if(($iplong = ip2long($addr)) === false) {
      throw new \Exception('Invalid IPv4 address');
    }
    $ptrname = inet_ntop(pack('V',$iplong)).'.in-addr.arpa';
    return $this->delRecord($ptrname, Message::TYPE_PTR);
  }

  public function start() {
    if($this->_socket) {
      return;
    }

    $socket = $this->_socket = new Socket;

    $socket->on('raw-message', function($message,$addr,$socket) {
      if(!DnsMessage::validQuery($message)) {
        return;
      }
      if(!($message = DnsMessage::decode($message))) {
        return;
      }

      $ucp = !preg_match('/\:5353$/', $addr);

      foreach($message->questions as $query) {
        $qu = $ucp ?: (bool)($query->class & 0x8000);
        $rrs = $this->_rr($query->name, $query->type);

        if(empty($rrs)) {
          continue;
        }

        foreach($rrs as $rr) {
          $dbg_rrdata = is_string($rr->data) ? ','.$rr->data : '';
          Log::debug('Responder: '.$addr.' asked for record['.$rr->name.','.$rr->type.$dbg_rrdata.']');

          $this->_queue[] = (object)[
            'i' => $ucp ? $message->id : null,
            'q' => $ucp ? $query : null,
            'r' => $rr,
            'a' => $qu ? $addr : null
          ];
        }
      }

      $this->_send();
    });
  }

  public function stop() {
    if(!$this->_socket) {
      return;
    }

    if($this->_send_timer) {
      Loop::cancelTimer($this->_send_timer);
      $this->_send_timer = null;
    }

    $this->_queue = [];
    $this->_socket->removeAllListeners();
    $this->_socket = null;
  }

  private function _send() {
    if(!$this->_socket || $this->_send_timer || empty($this->_queue)) {
      return;
    }

    $now_ms = round(microtime(true) * 1000);
    $dif_ms = $now_ms - $this->_send_ms;
    $delay = $dif_ms > 120 ? 0 : min($dif_ms + random_int(20, 120), 120);

    $data = array_shift($this->_queue);

    $sender = function() use($data) {
      $this->_send_timer = null;

      if(!$this->_socket) {
        return;
      }

      $response = new Message;
      $response->qr = true;
      $response->answers[] = $data->r;

      if($data->i) {
        $response->id = $data->i;
      }
      if($data->q) {
        $response->questions[] = $data->q;
      }

      $rrs = [$data->r];
      while($rr = array_shift($rrs)) {
        if($rr->type == Message::TYPE_PTR) {
          foreach($this->_rr($rr->data, Message::TYPE_ANY) as $addrr) {
            $key = $addrr->name.'|'.$addrr->type.'|'.(is_string($addrr->data)?$addrr->data:'');
            $response->additional[$key] = $addrr;
            if(in_array($addrr->type, [Message::TYPE_PTR,Message::TYPE_SRV])) {
              $rrs[] = $addrr;
            }
          }
        } else if($rr->type == Message::TYPE_SRV) {
          foreach($this->_rr($rr->data['target'], Message::TYPE_A) as $addrr) {
            $key = $addrr->name.'|'.Message::TYPE_A.'|'.$addrr->data;
            $response->additional[$key] = $addrr;
          }
          foreach($this->_rr($rr->data['target'], Message::TYPE_AAAA) as $addrr) {
            $key = $addrr->name.'|'.Message::TYPE_AAAA.'|'.$addrr->data;
            $response->additional[$key] = $addrr;
          }
        }
      }
      $response->additional = array_values($response->additional);

      $dbg_rrdata = is_string($data->r->data) ? ','.$data->r->data : '';
      Log::debug('Responder: send['.($data->a?:'QM').'] record['.$data->r->name.','.$data->r->type.$dbg_rrdata.']');

      $r = $this->_socket->send($response, $data->a);

      if($r === false && !empty($response->additional)) {
        $response->additional = [];
        Log::debug('Responder: send too big, remove additional and retry');
        $r = $this->_socket->send($response, $data->a);
      }

      if($r !== false) {
        Log::debug('Responder: send OK');
        $this->_send_ms = round(microtime(true) * 1000);
      }

      $this->_send();
    };

    if(!$delay) {
      $sender();
    } else {
      $this->_send_timer = Loop::addTimer($delay/1000, $sender);
    }
  }

  private function _rfy($rr=null, ...$args) {
    if(!self::$_rrfactory) self::$_rrfactory = new RecordFactory;
    return $rr ? self::$_rrfactory->$rr(...$args) : self::$_rrfactory;
  }

  private function _rr($name, $type) {
    $name = strtolower($name);
    $tlc = substr($name,0,3);
    $rrs = [];

    if($type == Message::TYPE_ANY) {
      if(!isset($this->_records[$tlc])) {
        return $rrs;
      }
      $recordss = &$this->_records[$tlc];
    } else if(!isset($this->_records[$tlc][$type])) {
      return $rrs;
    } else {
      $recordss = [$type => &$this->_records[$tlc][$type]];
    }

    foreach($recordss as $rrtype => &$records) {
      foreach($records as $record) {
        if($record->name != $name) {
          continue;
        }
        $rrs[] = $record;
      }
    }

    return $rrs;
  }
}
