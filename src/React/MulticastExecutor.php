<?php
namespace SharkyDog\mDNS\React;
use SharkyDog\mDNS\Socket;
use SharkyDog\mDNS\DnsMessage;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Promise\Deferred;

class MulticastExecutor implements ExecutorInterface {
  private $_filter;
  private $_filterQuery;
  private $_collector;

  public function setFilter(?callable $filter, bool $query=false) {
    if($query) {
      $this->_filterQuery = $filter;
    } else {
      $this->_filter = $filter;
    }
  }

  public function setCollector(Message $collector) {
    $this->_collector = $collector;
    $this->_collector->qr = true;
  }

  public function query(Query $query) {
    $message = Message::createRequestForQuery($query);
    $socket = new Socket;
    $rrtype = $query->type;
    $rrname = strtolower($query->name);

    $deferred = new Deferred(function() use($rrtype,$rrname) {
      throw new \RuntimeException('mDNS query for '.$rrname.'['.$rrtype.'] cancelled');
    });

    $collector = $this->_collector;
    $this->_collector = null;
    $filterQuery = $this->_filterQuery;
    $this->_filterQuery = null;

    $socket->on('raw-message', function($message,$addr) use($query,$rrtype,$rrname,$deferred,$collector,$filterQuery) {
      if(!DnsMessage::validReply($message,null,Message::RCODE_OK)) {
        return;
      }
      if(!($message = DnsMessage::decode($message))) {
        return;
      }

      $found = false;
      $filter = null;

      foreach($message->answers as $record) {
        if(!$found) {
          if($record->type != $rrtype) {
            continue;
          }
          if(strtolower($record->name) != $rrname) {
            continue;
          }
        }

        try {
          if($this->_filter) {
            if(($filter = ($this->_filter)($message,$addr,$query)) === false) {
              return;
            } else if($filter instanceOf Message) {
              $deferred->resolve($filter);
              return;
            }
          }
          if($filter === null && $filterQuery) {
            if(($filter = $filterQuery($message,$addr,$query)) === false) {
              return;
            } else if($filter instanceOf Message) {
              $deferred->resolve($filter);
              return;
            }
          }
        } catch(\Exception $e) {
          $deferred->reject($e);
          return;
        }

        $found = true;

        if($collector) {
          $collector->answers[] = $record;
        } else {
          $deferred->resolve($message);
          return;
        }
      }

      if($found && $collector) {
        foreach($message->additional as $record) {
          $collector->additional[] = $record;
        }
        if($filter === true) {
          $deferred->resolve($collector);
        }
      }
    });

    $socket->send($message);

    return $deferred->promise()->finally(function() use($socket) {
      $socket->removeAllListeners();
    });
  }
}
