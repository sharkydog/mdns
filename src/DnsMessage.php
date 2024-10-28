<?php
namespace SharkyDog\mDNS;
use React\Dns\Model\Message;
use React\Dns\Protocol\Parser as DnsParser;
use React\Dns\Protocol\BinaryDumper as DnsEncoder;

abstract class DnsMessage {
  private static $_decoder;
  private static $_encoder;

  public static function decode(string $message): ?Message {
    try {
      return self::_decoder()->parseMessage($message);
    } catch(\Exception $e) {
      return null;
    }
  }

  public static function encode(Message $message): string {
    return self::_encoder()->toBinary($message);
  }

  public static function valid(string $message, ?int $id=null, ?bool $qr=null, ?int $rcode=null): bool {
    if(strlen($message) < 12) {
      return false;
    }

    if($id !== null && $id != unpack('n',substr($message,0,2))[1]) {
      return false;
    }

    $fields = unpack('n',substr($message,2,2))[1];

    if($qr !== null && $qr != (bool)($fields & 0x8000)) {
      return false;
    }

    if($rcode !== null && $rcode != ($fields & 0x0f)) {
      return false;
    }

    return true;
  }

  public static function validQuery(string $message): bool {
    return self::valid($message, null, false, null);
  }

  public static function validReply(string $message, ?int $id=null, ?int $rcode=null): bool {
    return self::valid($message, $id, true, $rcode);
  }

  private static function _decoder() {
    if(!self::$_decoder) {
      self::$_decoder = new DnsParser;
    }
    return self::$_decoder;
  }

  private static function _encoder() {
    if(!self::$_encoder) {
      self::$_encoder = new DnsEncoder;
    }
    return self::$_encoder;
  }
}