<?php
namespace SharkyDog\mDNS;
use React\Dns\Model\Record;
use React\Dns\Model\Message;
use React\EventLoop\Loop;

class SimpleObserver {
  const N_ALL_ADDR = '_all_addr_';
  const N_ALL_SVC = '_all_svc_';
  const N_ALL = '_all_';

  const SVC_NEW = 1;
  const SVC_RENEW = 2;
  const SVC_EXPIRE = 4;
  const SVC_REMOVE = 8;
  const SVC_OFFLINE = 16;
  const SVC_ADDR_ONLINE = 32;
  const SVC_ADDR_OFFLINE = 64;
  const SVC_ALL = 255;

  const ADDR_NEW = 256;
  const ADDR_RENEW = 512;
  const ADDR_EXPIRE = 1024;
  const ADDR_REMOVE = 2048;
  const ADDR_OFFLINE = 4096;
  const ADDR_ALL = 65280;

  public $removeTimeout = 10;

  private $_sock;
  private $_hash = [];
  private $_hnds = [];

  private function _find_addr($name, $ip) {
    if(!isset($this->_hnds['addr'][$name]['addr'])) {
      return null;
    }
    foreach($this->_hnds['addr'][$name]['addr'] as $addr) {
      if($addr->address == $ip) return $addr;
    }
    return null;
  }

  private function _to_name_type(&$name, &$type) {
    if($name == self::N_ALL_ADDR) {
      $name = self::N_ALL;
      $type = 'addr';
    } else if($name == self::N_ALL_SVC) {
      $name = self::N_ALL;
      $type = 'svci';
    } else if(preg_match('/^(?:(.+)\.)?(_[^\.]+\.(?:_tcp|_udp)\.local)$/i', $name, $m)) {
      $type = $m[1] ? 'svci' : 'svct';
    } else {
      $type = 'addr';
    }
  }

  private function _add_listener($type, $name, $cbck, $evts) {
    if(!isset($this->_hnds[$type])) {
      $this->_hnds[$type] = [];
    }
    if(!isset($this->_hnds[$type][$name])) {
      $this->_hnds[$type][$name] = ['hnds' => new \SplObjectStorage];
    }

    if(!$cbck) {
      return;
    }

    if(!$this->_hnds[$type][$name]['hnds']->contains($cbck)) {
      $this->_hnds[$type][$name]['hnds']->attach($cbck, 0);
    }

    $this->_hnds[$type][$name]['hnds'][$cbck] = $evts;

    if($type == 'svct' && !isset($this->_hnds['svct'][$name]['cbck'])) {
      $cbck = function($evt, ...$args) use($name) {
        $this->_svct_on_svci($name, $evt, ...$args);
      };
      $this->_add_listener('svci', self::N_ALL, $cbck, 0);
      $this->_hnds['svct'][$name]['cbck'] = $cbck;
    }
  }

  private function _remove_listener($type, $name, $cbck) {
    if(!isset($this->_hnds[$type][$name])) {
      return;
    }
    if(!$this->_hnds[$type][$name]['hnds']->contains($cbck)) {
      return;
    }

    $this->_hnds[$type][$name]['hnds']->detach($cbck);

    if($name != self::N_ALL && isset($this->_hnds[$type][self::N_ALL])) {
      return;
    }
    if($this->_hnds[$type][$name]['hnds']->count()) {
      return;
    }

    if($type == 'svct') {
      $cbck = $this->_hnds['svct'][$name]['cbck'];
      $this->_remove_listener('svci', self::N_ALL, $cbck);
      unset($this->_hnds['svct'][$name]['cbck']);
    }
    else if($type == 'svci') {
      $trgt = $this->_hnds['svci'][$name]['trgt'];
      $cbck = $this->_hnds['svci'][$name]['cbck'];
      $this->_remove_listener('addr', $trgt, $cbck);
      Loop::cancelTimer($this->_hnds['svci'][$name]['tmr']);
      unset($this->_hnds['svci'][$name]['trgt']);
      unset($this->_hnds['svci'][$name]['cbck']);
    }
    else if($type == 'addr') {
      if($addrs = $this->_hnds['addr'][$name]['addr'] ?? null) {
        foreach($addrs as $addr) {
          Loop::cancelTimer($addrs[$addr]);
        }
      }
    }

    unset($this->_hnds[$type][$name]);

    if(empty($this->_hnds[$type])) {
      unset($this->_hnds[$type]);
    }
  }

  private function _notify($evt, $type, $name, ...$args) {
    if(!isset($this->_hnds[$type][$name])) {
      return;
    }

    $cbcks = clone $this->_hnds[$type][$name]['hnds'];

    foreach($cbcks as $cbck) {
      if(($evts = $cbcks[$cbck]) && !($evts & $evt)) {
        continue;
      }
      $cbck($evt, ...$args);
    }

    if($name != self::N_ALL) {
      $this->_notify($evt, $type, self::N_ALL, ...$args);
    }
  }

  private function _svct_on_svci($name, $evt, ...$args) {
    $svc = $args[0];
    $trgt = strtolower($svc->name);

    if(substr($trgt, 0-(strlen($name)+1)) != '.'.$name) {
      return;
    }

    $this->_notify($evt, 'svct', $name, ...$args);
  }

  private function _svci_on_addr($name, $evt, $addr) {
    $svc = $this->_hnds['svci'][$name]['svc'];

    if($evt == self::ADDR_NEW) {
      $svc->target[] = $addr;
    } else if($evt == self::ADDR_RENEW) {
      $found = false;
      foreach($svc->target as $k => $saddr) {
        if($saddr !== $addr) continue;
        $found = true;
        break;
      }
      if(!$found) $svc->target[] = $addr;
    } else if($evt == self::ADDR_REMOVE) {
      foreach($svc->target as $k => $saddr) {
        if($saddr !== $addr) continue;
        unset($svc->target[$k]);
        break;
      }
    }

    if($evt == self::ADDR_NEW || $evt == self::ADDR_RENEW) {
      if($addr->type == Message::TYPE_A && !($svc->status & Observer\Service::S_HAS_IP4)) {
        $svc->status |= Observer\Service::S_HAS_IP4;
        $evt |= self::SVC_ADDR_ONLINE;
      } else if($addr->type == Message::TYPE_AAAA && !($svc->status & Observer\Service::S_HAS_IP6)) {
        $svc->status |= Observer\Service::S_HAS_IP6;
        $evt |= self::SVC_ADDR_ONLINE;
      }
    } else if($evt == self::ADDR_REMOVE || $evt == self::ADDR_OFFLINE) {
      $ip4 = $ip6 = false;

      foreach($svc->target as $k => $saddr) {
        if(!($saddr->status & Observer\Address::S_ONLINE)) {
          continue;
        }
        if($saddr->type == Message::TYPE_A) {
          $ip4 = true;
        } else if($saddr->type == Message::TYPE_AAAA) {
          $ip6 = true;
        }
      }

      if(!$ip4 && ($svc->status & Observer\Service::S_HAS_IP4)) {
        $svc->status &= ~Observer\Service::S_HAS_IP4;
        $evt |= self::SVC_ADDR_OFFLINE;
      }
      if(!$ip6 && ($svc->status & Observer\Service::S_HAS_IP6)) {
        $svc->status &= ~Observer\Service::S_HAS_IP6;
        $evt |= self::SVC_ADDR_OFFLINE;
      }
    }

    if($svc->status & Observer\Service::S_PENDING_NOTIFY) {
      $evt &= ~(self::SVC_ADDR_ONLINE | self::SVC_ADDR_OFFLINE);
    }
    $this->_notify($evt, 'svci', $name, $svc, $addr);
  }

  private function _svci_expire($name) {
    $svc = $this->_hnds['svci'][$name]['svc'];

    $svc->status |= Observer\Service::S_EXPIRED;
    Loop::cancelTimer($this->_hnds['svci'][$name]['tmr']);

    $this->_hnds['svci'][$name]['tmr'] = Loop::addTimer((float)$this->removeTimeout, function() use($name) {
      $this->_svci_remove($name);
    });

    $this->_notify(self::SVC_EXPIRE, 'svci', $name, $svc);
  }

  private function _svci_remove($name) {
    $svc = $this->_hnds['svci'][$name]['svc'];
    $svc->status |= Observer\Service::S_DETACHED;
    unset($this->_hnds['svci'][$name]['svc']);

    $trgt = $this->_hnds['svci'][$name]['trgt'];
    $cbck = $this->_hnds['svci'][$name]['cbck'];
    $this->_remove_listener('addr', $trgt, $cbck);
    unset($this->_hnds['svci'][$name]['trgt']);
    unset($this->_hnds['svci'][$name]['cbck']);

    $this->_notify(self::SVC_REMOVE, 'svci', $name, $svc);
  }

  private function _addr_expire($name, $ip) {
    $addrs = $this->_hnds['addr'][$name]['addr'];
    $addr = $this->_find_addr($name, $ip);

    $addr->status |= Observer\Address::S_EXPIRED;
    Loop::cancelTimer($addrs[$addr]);

    $addrs[$addr] = Loop::addTimer((float)$this->removeTimeout, function() use($name, $ip) {
      $this->_addr_remove($name, $ip);
    });

    $this->_notify(self::ADDR_EXPIRE, 'addr', $name, $addr);
  }

  private function _addr_remove($name, $ip) {
    $addr = $this->_find_addr($name, $ip);
    $addr->status |= Observer\Address::S_DETACHED;
    $addrs = $this->_hnds['addr'][$name]['addr'];

    $addrs->detach($addr);

    if(!$addrs->count()) {
      unset($this->_hnds['addr'][$name]['addr']);
    }

    $this->_notify(self::ADDR_REMOVE, 'addr', $name, $addr);
  }

  private function _sock_cb($data, $from) {
    if(empty($this->_hnds)) {
      return;
    }
    if(!DnsMessage::validReply($data, null, Message::RCODE_OK)) {
      return;
    }

    $hash = sha1($data);
    if(isset($this->_hash[$hash])) {
      return;
    }
    $this->_hash[$hash] = Loop::addTimer(5, function() use($hash) {
      unset($this->_hash[$hash]);
    });

    if(!($msg = DnsMessage::decode($data))) {
      return;
    }

    $msg->answers = array_merge($msg->answers, $msg->additional);
    $msg->additional = [];
    $srv = $txt = $ips = [];
    $notify = []; $i = 0;

    foreach($msg->answers as $rr) {
      $name = strtolower($rr->name);

      if(!$rr->ttl) {
        if(!($rr->class & 0x8000)) {
          continue;
        }

        if($rr->type == Message::TYPE_SRV) {
          if(!($svc = $this->_hnds['svci'][$name]['svc'])) {
            continue;
          }

          $svc->status &= ~(Observer\Service::S_ONLINE | Observer\Service::S_EXPIRED);
          Loop::cancelTimer($this->_hnds['svci'][$name]['tmr']);

          $this->_hnds['svci'][$name]['tmr'] = Loop::addTimer((float)$this->removeTimeout, function() use($name) {
            $this->_svci_remove($name);
          });

          $svc->status |= Observer\Service::S_PENDING_NOTIFY;
          $nk = 's'.(++$i);
          $notify[$nk] = [self::SVC_OFFLINE,'svci',$name,$svc,$svc->status];
        } else if($rr->type == Message::TYPE_A || $rr->type == Message::TYPE_AAAA) {
          if(!($addr = $this->_find_addr($name, $rr->data))) {
            continue;
          }

          $ip = $rr->data;
          $addrs = $this->_hnds['addr'][$name]['addr'];

          $addr->status &= ~(Observer\Address::S_ONLINE | Observer\Address::S_EXPIRED);
          Loop::cancelTimer($addrs[$addr]);

          $addrs[$addr] = Loop::addTimer((float)$this->removeTimeout, function() use($name, $ip) {
            $this->_addr_remove($name, $ip);
          });

          $addr->status |= Observer\Address::S_PENDING_NOTIFY;
          $nk = 'a'.(++$i);
          $notify[$nk] = [self::ADDR_OFFLINE,'addr',$name,$addr];
        }

        continue;
      }

      if($rr->type == Message::TYPE_SRV) {
        if(isset($this->_hnds['svci'][self::N_ALL])) {
          if(!isset($this->_hnds['svci'][$name])) {
            $this->_add_listener('svci', $name, null, 0);
          }
        }
        $srv[$name] = $rr;
      } else if($rr->type == Message::TYPE_TXT) {
        $txt[$name] = $rr;
      } else if($rr->type == Message::TYPE_A || $rr->type == Message::TYPE_AAAA) {
        if(isset($this->_hnds['addr'][self::N_ALL])) {
          if(!isset($this->_hnds['addr'][$name])) {
            $this->_add_listener('addr', $name, null, 0);
          }
        }
        if(!isset($ips[$name])) $ips[$name] = [];
        $ips[$name][$rr->data] = $rr;
      }
    }

    foreach($srv as $name => $rr) {
      $svc = $this->_hnds['svci'][$name]['svc'] ?? null;
      $trgt = strtolower($rr->data['target']);

      if(!isset($this->_hnds['svci'][$name])) {
        continue;
      }

      if(($strgt = $this->_hnds['svci'][$name]['trgt'] ?? null) && $strgt != $trgt) {
        $cbck = $this->_hnds['svci'][$name]['cbck'];
        unset($this->_hnds['svci'][$name]['cbck']);
        unset($this->_hnds['svci'][$name]['trgt']);
        $this->_remove_listener('addr', $strgt, $cbck);

        foreach(($addrs = $svc->target) as $addr) {
          $this->_svci_on_addr($name, self::ADDR_REMOVE, $addr);
        }
      }

      if(!isset($this->_hnds['svci'][$name]['cbck'])) {
        $cbck = function($evt, $addr) use($name) {
          $this->_svci_on_addr($name, $evt, $addr);
        };
        $this->_add_listener('addr', $trgt, $cbck, 0);
        $this->_hnds['svci'][$name]['cbck'] = $cbck;
        $this->_hnds['svci'][$name]['trgt'] = $trgt;
      }

      if(!$svc) {
        $svc = $this->_hnds['svci'][$name]['svc'] = new Observer\Service;
        $svc->name = $rr->name;
        $evt = self::SVC_NEW;
      } else {
        Loop::cancelTimer($this->_hnds['svci'][$name]['tmr']);
        $svc->status &= ~Observer\Service::S_EXPIRED;
        $evt = self::SVC_RENEW;
      }

      $nk = 's'.(++$i);
      $notify[$nk] = [$evt,'svci',$name,$svc];
      $notify[$nk][4] = $svc->status;

      $svc->priority = $rr->data['priority'];
      $svc->weight = $rr->data['weight'];
      $svc->port = $rr->data['port'];
      $svc->status |= (Observer\Service::S_ONLINE | Observer\Service::S_PENDING_NOTIFY);
      $svc->expire = time() + $rr->ttl;

      $this->_hnds['svci'][$name]['tmr'] = Loop::addTimer($rr->ttl, function() use($name) {
        $this->_svci_expire($name);
      });

      if($evt == self::SVC_NEW) {
        foreach(($this->_hnds['addr'][$trgt]['addr'] ?? []) as $addr) {
          if(isset($ips[$trgt][$addr->address])) {
            continue;
          }

          if($addr->status & Observer\Address::S_ONLINE) {
            $aevt = self::ADDR_NEW;
          } else {
            $svc->target[] = $addr;
            $aevt = self::ADDR_OFFLINE;
          }

          $this->_svci_on_addr($name, $aevt, $addr);
        }
      }
    }

    foreach($txt as $name => $rr) {
      if(!($svc = $this->_hnds['svci'][$name]['svc'] ?? null)) {
        continue;
      }

      $svc->data = [];
      $svc->status |= Observer\Service::S_HAS_TXT;

      foreach($rr->data as $data) {
        if(!preg_match('/^([^\=]+)(.*)$/', $data, $m)) continue;
        $svc->data[$m[1]] = $m[2] ? substr($m[2],1) : true;
      }
    }

    foreach($ips as $name => $rrs) {
      if(!isset($this->_hnds['addr'][$name])) {
        continue;
      }

      if(!($addrs = $this->_hnds['addr'][$name]['addr'] ?? null)) {
        $addrs = $this->_hnds['addr'][$name]['addr'] = new \SplObjectStorage;
      }

      foreach($rrs as $ip => $rr) {
        if(!($addr = $this->_find_addr($name, $ip))) {
          $addr = new Observer\Address;
          $addr->name = $rr->name;
          $addr->type = $rr->type;
          $addr->address = $rr->data;
          $addrs->attach($addr);
          $evt = self::ADDR_NEW;
        } else {
          Loop::cancelTimer($addrs[$addr]);
          $addr->status &= ~Observer\Address::S_EXPIRED;
          $evt = self::ADDR_RENEW;
        }

        $nk = 'a'.(++$i);
        $notify[$nk] = [$evt,'addr',$name,$addr];

        $addr->status |= (Observer\Address::S_ONLINE | Observer\Address::S_PENDING_NOTIFY);
        $addr->expire = time() + $rr->ttl;

        $addrs[$addr] = Loop::addTimer($rr->ttl, function() use($name, $ip) {
          $this->_addr_expire($name, $ip);
        });
      }
    }

    if(!empty($notify)) {
      ksort($notify);

      foreach($notify as $args) {
        if($args[1] == 'svci') {
          $args[3]->status &= ~Observer\Service::S_PENDING_NOTIFY;

          $had = $args[4] & Observer\Service::S_HAS_IP4;
          $has = $args[3]->status & Observer\Service::S_HAS_IP4;

          if($had != $has) {
            $args[0] |= $has ? self::SVC_ADDR_ONLINE : self::SVC_ADDR_OFFLINE;
          }

          $had = $args[4] & Observer\Service::S_HAS_IP6;
          $has = $args[3]->status & Observer\Service::S_HAS_IP6;

          if($had != $has) {
            $args[0] |= $has ? self::SVC_ADDR_ONLINE : self::SVC_ADDR_OFFLINE;
          }

          unset($args[4]);
        } else if($args[1] == 'addr') {
          $args[3]->status &= ~Observer\Address::S_PENDING_NOTIFY;
        }

        $this->_notify(...$args);
      }
    }
  }

  public function addListener(string $name, callable $callback, int $events=0): \Closure {
    if(!($name = strtolower(trim($name)))) {
      throw new \Exception('name is empty');
    }

    $callback = \Closure::fromCallable($callback);

    if($name == self::N_ALL) {
      $this->_add_listener('addr', self::N_ALL, $callback, $events);
      $this->_add_listener('svci', self::N_ALL, $callback, $events);
      return $callback;
    }

    $this->_to_name_type($name, $type);
    $this->_add_listener($type, $name, $callback, $events);

    return $callback;
  }

  public function removeListener(string $name, \Closure $callback) {
    if(!($name = strtolower(trim($name)))) {
      throw new \Exception('name is empty');
    }

    if($name == self::N_ALL) {
      $this->_remove_listener('addr', self::N_ALL, $callback);
      $this->_remove_listener('svci', self::N_ALL, $callback);
      return;
    }

    $this->_to_name_type($name, $type);
    $this->_remove_listener($type, $name, $callback);
  }

  public function start() {
    if($this->_sock) return;
    $this->_sock = new Socket;
    $this->_sock->on('raw-message', function($data, $from) {
      $this->_sock_cb($data, $from);
    });
  }

  public function stop(bool $clean=true) {
    if(!$this->_sock) return;
    $this->_sock->removeAllListeners();
    $this->_sock = null;

    if(!$clean) return;
    $this->clean();
  }

  public function clean() {
    foreach($this->_hash as $hash => $timer) Loop::cancelTimer($timer);
    $this->_hash = [];

    foreach(($this->_hnds['addr']??[]) as $name => $data) {
      if(!isset($data['addr'])) continue;
      $addrs = clone $data['addr'];
      foreach($addrs as $addr) {
        Loop::cancelTimer($data['addr'][$addr]);
        $this->_addr_remove($name, $addr->address);
      }
    }

    foreach(($this->_hnds['svci']??[]) as $name => $data) {
      if(!isset($data['svc'])) continue;
      Loop::cancelTimer($data['tmr']);
      unset($this->_hnds['svci'][$name]['tmr']);
      $this->_svci_remove($name);
    }

    foreach(($this->_hnds['addr']??[]) as $name => $data) {
      if($name == self::N_ALL || $data['hnds']->count()) continue;
      unset($this->_hnds['addr'][$name]);
    }

    foreach(($this->_hnds['svci']??[]) as $name => $data) {
      if($name == self::N_ALL || $data['hnds']->count()) continue;
      unset($this->_hnds['svci'][$name]);
    }

    if(empty($this->_hnds['addr'])) {
      unset($this->_hnds['addr']);
    }

    if(empty($this->_hnds['svci'])) {
      unset($this->_hnds['svci']);
    }
  }
}
