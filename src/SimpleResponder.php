<?php
namespace SharkyDog\mDNS;
use React\Dns\Model\Record;
use React\Dns\Model\Message;
use React\EventLoop\Loop;

class SimpleResponder {
  private static $_rrfactory;
  private $_rrstore;
  private $_socket;
  private $_queue = [];
  private $_send_ms = 0;
  private $_send_timer;

  public function __construct(?RecordStorage $rrstore=null) {
    $this->_rrstore = $rrstore ?? new RecordStorage;
  }

  public function addRecordIPv4(string $name, string $addr, int $ttl=120, bool $cfbit=true) {
    $this->addRecord($this->_rfy('A', $name, $addr, $ttl), $cfbit);
  }

  public function addRecordIPv6(string $name, string $addr, int $ttl=120, bool $cfbit=true) {
    $this->addRecord($this->_rfy('AAAA', $name, $addr, $ttl), $cfbit);
  }

  public function addRecord(Record $record, bool $cfbit=false) {
    $this->_rrstore->addRecord($record, $cfbit);
  }

  public function delRecord(string $name, int $type, $data=null): bool {
    return $this->_rrstore->delRecord($name, $type, $data);
  }

  public function enableRecord(string $name, int $type, $data=null, bool $enable=true) {
    $this->_rrstore->enableRecord($name, $type, $data, $enable);
  }

  public function addService(string $type, string $instance, ?int $ttl=null, ?string $target=null, int $srvport=0, string ...$txts) {
    $ttl = $this->_rfy()->DefaultTTL($ttl);

    $svcname = $type.'.local';
    $svctrgt = $instance.'.'.$svcname;
    $this->addRecord($this->_rfy('PTR', $svcname, $svctrgt, $ttl));

    $rr = $this->_rr('_services._dns-sd._udp.local', Message::TYPE_PTR, strtolower($svcname), null)[0] ?? null;

    if(!$rr) {
      $this->addRecord($this->_rfy('PTR', '_services._dns-sd._udp.local', $svcname, $ttl), false);
    } else {
      $rr->class &= ~0x4000;
    }

    if(!$target) {
      return;
    }

    if(!($this->_rr($svctrgt, Message::TYPE_SRV, null, null)[0] ?? null)) {
      $this->addRecord($this->_rfy('SRV', $svctrgt, 0, 0, $srvport, $target, $ttl), true);
    }

    if(!($this->_rr($svctrgt, Message::TYPE_TXT, null, null)[0] ?? null)) {
      $this->addRecord($this->_rfy('TXT', $svctrgt, $ttl, ...$txts), true);
    }
  }

  public function delService(string $type, string $instance, bool $srv=true, bool $txt=true) {
    $svcname = $type.'.local';
    $svctrgt = $instance.'.'.$svcname;

    $this->delRecord($svcname, Message::TYPE_PTR, strtolower($svctrgt));

    if($srv) {
      $this->delRecord($svctrgt, Message::TYPE_SRV);
    }
    if($txt) {
      $this->delRecord($svctrgt, Message::TYPE_TXT);
    }

    if(!count($this->_rr($svcname, Message::TYPE_PTR, null, null))) {
      $this->delRecord('_services._dns-sd._udp.local', Message::TYPE_PTR, strtolower($svcname));
    }
  }

  public function enableService(string $type, string $instance, bool $enable=true) {
    $svcname = $type.'.local';
    $svctrgt = $instance.'.'.$svcname;

    $this->enableRecord($svctrgt, Message::TYPE_SRV, null, $enable);
    $this->enableRecord($svctrgt, Message::TYPE_TXT, null, $enable);
    $this->enableRecord($svcname, Message::TYPE_PTR, strtolower($svctrgt), $enable);

    if($enable || !count($this->_rr($svcname, Message::TYPE_PTR, null, true))) {
      $this->enableRecord('_services._dns-sd._udp.local', Message::TYPE_PTR, strtolower($svcname), $enable);
    }
  }

  public function advertiseService(string $type, string $instance, ?int $ttl=null) {
    if(!$this->_socket) {
      return;
    }

    $svcname = $type.'.local';
    $svctrgt = $instance.'.'.$svcname;

    if(!($rr = $this->_rr($svcname, Message::TYPE_PTR, strtolower($svctrgt))[0] ?? null)) {
      return;
    }

    if($ttl !== null) {
      $ttl = min(max(0,$ttl),0x7fffffff);
    }

    $this->_queue[] = (object)[
      'rr' => $rr,
      'ttl' => $ttl,
      'advt' => true
    ];

    $this->_send();
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
            'id'   => $ucp ? $message->id : null,
            'qry'  => $ucp ? $query : null,
            'rr'   => $rr,
            'addr' => $qu ? $addr : null
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

      $data->qry = $data->qry ?? null;
      $response = new Message;
      $response->qr = true;
      $response->aa = true;
      $response->answers[] = clone $data->rr;

      if($data->id ?? null) {
        $response->id = $data->id;
      }
      if($data->qry) {
        $response->questions[] = $data->qry;
      }

      if($data->adtrr ?? true) {
        $rrs = [$data->rr];
        while($rr = array_shift($rrs)) {
          if($rr->type == Message::TYPE_PTR) {
            foreach($this->_rr($rr->data, Message::TYPE_ANY) as $addrr) {
              $key = $addrr->name.'|'.$addrr->type.'|'.(is_string($addrr->data)?$addrr->data:'');
              $response->additional[$key] = clone $addrr;
              if(in_array($addrr->type, [Message::TYPE_PTR,Message::TYPE_SRV])) {
                $rrs[] = $addrr;
              }
            }
          } else if($rr->type == Message::TYPE_SRV) {
            foreach($this->_rr($rr->data['target'], Message::TYPE_A) as $addrr) {
              $key = $addrr->name.'|'.Message::TYPE_A.'|'.$addrr->data;
              $response->additional[$key] = clone $addrr;
            }
            foreach($this->_rr($rr->data['target'], Message::TYPE_AAAA) as $addrr) {
              $key = $addrr->name.'|'.Message::TYPE_AAAA.'|'.$addrr->data;
              $response->additional[$key] = clone $addrr;
            }
          }
        }
        $response->additional = array_values($response->additional);
      }

      if($data->qry) {
        foreach($response->answers as $rr) {
          $rr->class &= ~0x8000;
        }
        foreach($response->additional as $rr) {
          $rr->class &= ~0x8000;
        }
      }

      if(($data->ttl ?? null) !== null) {
        foreach($response->answers as $rr) {
          $rr->ttl = $data->ttl;
        }
        foreach($response->additional as $rr) {
          $rr->ttl = $data->ttl;
        }
      }

      if($data->advt ?? false) {
        $response->answers = array_merge($response->answers, $response->additional);
        $response->additional = [];
      }

      $data->addr = $data->addr ?? null;
      $dbg_rrdata = is_string($data->rr->data) ? ','.$data->rr->data : '';
      Log::debug('Responder: send['.($data->addr?:'QM').'] record['.$data->rr->name.','.$data->rr->type.$dbg_rrdata.']');

      $r = $this->_socket->send($response, $data->addr);

      if($r === false) {
        $rrs = array_merge($response->answers, $response->additional);
        $response->answers = [array_shift($rrs)];
        $response->additional = [];

        Log::debug('Responder: message too big, send the first record');
        $r = $this->_socket->send($response, $data->addr);
      } else {
        $rrs = [];
      }

      if(!empty($rrs) && !$data->qry) {
        Log::debug('Responder: message too big, queue additional records');

        foreach($rrs as $rr) {
          $dt = clone $data;
          $dt->rr = $rr;
          $dt->ttl = null;
          $dt->advt = false;
          $dt->adtrr = false;
          $this->_queue[] = $dt;
        }
      }

      if($r !== false) {
        Log::debug('Responder: send OK');
        $this->_send_ms = round(microtime(true) * 1000);
      } else {
        Log::debug('Responder: send failed');
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

  private function _rr($name, $type, $data=null, $active=true) {
    return $this->_rrstore->findRecords($name, $type, $data, $active);
  }
}
