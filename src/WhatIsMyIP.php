<?php
namespace SharkyDog\mDNS;
use React\Dns\Model\Record;
use React\Dns\Model\Message;
use React\Promise;

abstract class WhatIsMyIP {
  public static $domain = '_my_lan_ip._test.local';
  private static $_responder;

  public static function resolveIPv4(string $network='', ?React\Resolver $resolver=null): Promise\PromiseInterface {
    if(!$resolver) {
      $resolver = new React\Resolver;
    }

    if($network) {
      try {
        $network = self::_parseNetIPv4($network);
      } catch(\Exception $e) {
        return Promise\reject($e);
      }

      if(Log::loggerLoaded()) {
        $netw = $network->addr << $network->shft;
        $bcst = long2ip($netw + ((2 ** $network->shft) - 1));
        $netw = long2ip($netw).'/'.(32 - $network->shft);
        Log::debug('WhatIsMyIP: resolver: '.$netw.', '.$bcst);
      }
    }

    $resolver->setMDnsQueryFilter(function($message,$addr) use($network) {
      if(!$network) {
        return;
      }
      if(!($addr = (parse_url('udp://'.$addr)['host'] ?? null))) {
        return false;
      }
      if(($addr = ip2long($addr)) === false) {
        return false;
      }
      if(($addr >> $network->shft) != $network->addr) {
        return false;
      }
    });

    return $resolver->resolve(static::$domain);
  }

  public static function startResponderIPv4(string $network=''): bool {
    if(self::$_responder) {
      return false;
    }

    if($network) {
      $network = self::_parseNetIPv4($network);

      if(Log::loggerLoaded()) {
        $netw = $network->addr << $network->shft;
        $bcst = long2ip($netw + ((2 ** $network->shft) - 1));
        $netw = long2ip($netw).'/'.(32 - $network->shft);
        Log::debug('WhatIsMyIP: responder: '.$netw.', '.$bcst);
      }
    }

    Log::debug('WhatIsMyIP: responder: start');
    $socket = self::$_responder = new Socket;

    $socket->on('raw-message', function($data,$addr,$socket) use($network) {
      if(!DnsMessage::validQuery($data)) {
        return;
      }
      if(!($ipstr = (parse_url('udp://'.$addr)['host'] ?? null))) {
        return;
      }

      if($network) {
        if(($iplong = ip2long($ipstr)) === false) {
          return;
        }
        if(($iplong >> $network->shft) != $network->addr) {
          return;
        }
      }

      $socket->once('dns-message', function($message,$addr,$socket) use($ipstr) {
        $found = false;

        foreach($message->questions as $query) {
          if($query->name != static::$domain) {
            continue;
          }
          if($query->type != Message::TYPE_A) {
            continue;
          }
          $found = true;
          break;
        }

        if(!$found) {
          return;
        }

        $reply = new Message;
        $reply->id = $message->id;
        $reply->qr = true;

        $reply->questions[] = $query;
        $reply->answers[] = new Record(
          static::$domain, Message::TYPE_A,
          Message::CLASS_IN, 120, $ipstr
        );

        Log::debug('WhatIsMyIP: responder: sending reply to '.$addr);
        $socket->send($reply, $addr);
      });
    });

    return true;
  }

  public static function stopResponderIPv4(): bool {
    if(!self::$_responder) {
      return false;
    }

    Log::debug('WhatIsMyIP: responder: stop');
    self::$_responder->removeAllListeners();
    self::$_responder = null;

    return true;
  }

  private static function _parseNetIPv4($network) {
    if(!preg_match('/^([\d\.]+)(?:\/(\d+))?$/', $network, $m)) {
      throw new \Exception('Invalid IP address/cidr');
    }
    if(!($cidr = (int)($m[2] ?? 32)) || $cidr>32) {
      throw new \Exception('Invalid CIDR mask');
    }
    if(($addr = ip2long($m[1])) === false) {
      throw new \Exception('Invalid IP address');
    }

    if($addr && $cidr<32) {
      $shft = 32 - $cidr;
      $addr = $addr >> $shft;
    } else {
      $shft = 0;
    }

    return (object)['addr'=>$addr,'shft'=>$shft];
  }
}
