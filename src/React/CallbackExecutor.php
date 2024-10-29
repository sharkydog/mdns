<?php
namespace SharkyDog\mDNS\React;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;

class CallbackExecutor implements ExecutorInterface {
  private $_callback;

  public function __construct(callable $callback) {
    $this->_callback = $callback;
  }

  public function query(Query $query) {
    return ($this->_callback)($query);
  }
}
