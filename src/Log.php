<?php
namespace SharkyDog\mDNS;

class Log {
  private static $_logger = null;

  public static function loggerLoaded(): bool {
    if(self::$_logger === null) {
      self::$_logger = class_exists('SharkyDog\Log\Logger');
    }
    return self::$_logger;
  }

  public static function __callStatic($name, $args) {
    if(self::loggerLoaded()) {
      \SharkyDog\Log\Logger::$name(...$args);
    } else if(method_exists(self::class, ($name = '_fn_'.$name))) {
      self::$name(...$args);
    }
  }

  private static function _fn_error(string $msg) {
    print "Error: ".$msg."\n";
  }
  private static function _fn_warning(string $msg) {
    print "Warning: ".$msg."\n";
  }
}
