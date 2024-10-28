<?php
namespace SharkyDog\mDNS\React;
use React\Dns\Resolver\ResolverInterface;
use React\Dns\Resolver\Resolver as ReactResolver;
use React\Dns\Query\TimeoutExecutor;
use React\Dns\Query\CoopExecutor;
use React\Dns\Model\Message;

class Resolver implements ResolverInterface {
  private $_resolver;
  private $_resolver_dns;
  private $_executor_mdns;
  private $_collector;
  private $_extractor;

  public function __construct(int $timeout=2, bool $unicast=true, ?ResolverInterface $dnsResolver=null) {
    $executor = $unicast ? new UnicastExecutor : new MulticastExecutor;
    $this->_executor_mdns = $executor;

    $executor = new TimeoutExecutor($executor, $timeout);
    $executor = new TimeoutCaptureExecutor($executor, function($e) {
      if($this->_collector) return $this->_collector;
      throw $e;
    });
    $executor = new MessageExtractExecutor($executor, function($message) {
      if(!$this->_extractor) return;
      ($this->_extractor)($message);
    });

    $executor = new CoopExecutor($executor);
    $this->_resolver = new ReactResolver($executor);
    $this->_resolver_dns = $dnsResolver;
  }

  public function setMDnsFilter(?callable $filter) {
    $this->_executor_mdns->setFilter($filter);
  }

  public function setDnsResolver(?ResolverInterface $dnsResolver) {
    $this->_resolver_dns = $dnsResolver;
  }

  public function resolve($domain) {
    return $this->_getResolver($domain)->resolve($domain);
  }

  public function resolveAll($domain, $type, bool $multi=false, bool $additional=false) {
    $resolver = $this->_getResolver($domain);

    if($resolver !== $this->_resolver) {
      return $resolver->resolveAll($domain, $type);
    }

    if($multi) {
      $this->_collector = new Message;
      $this->_executor_mdns->setCollector($this->_collector);
    }

    $extractedMessage = null;
    $this->_extractor = function($message) use($domain, $type, &$extractedMessage) {
      $domain = strtolower($domain);

      foreach($message->answers as $k => $record) {
        if(strtolower($record->name) == $domain && $record->type == $type) {
          continue;
        }

        unset($message->answers[$k]);
        array_unshift($message->additional, $record);
      }

      $message->answers = array_values($message->answers);
      $extractedMessage = $message;
    };

    $promise = $resolver->resolveAll($domain, $type);

    $promise = $promise->then(function($data) use($additional, &$extractedMessage) {
      if($additional) {
        $data['additional'] = $extractedMessage->additional;
      }
      $this->_extractor = null;
      return $data;
    });

    if($multi) {
      $promise = $promise->finally(function() {
        $this->_collector = null;
      });
    }

    return $promise;
  }

  private function _getResolver($domain) {
    if(!$this->_resolver_dns || preg_match('/\.local$/i',$domain)) {
      return $this->_resolver;
    } else {
      return $this->_resolver_dns;
    }
  }
}
