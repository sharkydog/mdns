<?php
namespace SharkyDog\mDNS\React;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\Model\Message;

class MessageExtractExecutor implements ExecutorInterface {
  private $_executor;
  private $_callback;

  public function __construct(ExecutorInterface $executor, callable $callback) {
    $this->_executor = $executor;
    $this->_callback = $callback;
  }

  public function query(Query $query) {
    return $this->_executor->query($query)->then(function(Message $message) {
      ($this->_callback)($message);
      return $message;
    });
  }
}