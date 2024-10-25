<?php
namespace SharkyDog\mDNS\React;
use React\Dns\Resolver\ResolverInterface;
use React\Dns\Resolver\Resolver as ReactResolver;
use React\Dns\Query\TimeoutExecutor;
use React\Dns\Query\CoopExecutor;

class Resolver implements ResolverInterface {
  private $_resolver;
  private $_resolver_dns;

  public function __construct(int $timeout=2, bool $unicast=true, ?ResolverInterface $dnsResolver=null) {
    $executor = $unicast ? new UnicastExecutor : new MulticastExecutor;
    $executor = new TimeoutExecutor($executor, $timeout);
    $executor = new CoopExecutor($executor);
    $this->_resolver = new ReactResolver($executor);
    $this->_resolver_dns = $dnsResolver;
  }

  public function setDnsResolver(?ResolverInterface $dnsResolver) {
    $this->_resolver_dns = $dnsResolver;
  }

  public function resolve($domain) {
    return $this->_getResolver($domain)->resolve($domain);
  }

  public function resolveAll($domain, $type) {
    return $this->_getResolver($domain)->resolveAll($domain, $type);
  }

  private function _getResolver($domain) {
    if(!$this->_resolver_dns || preg_match('/\.local$/i',$domain)) {
      return $this->_resolver;
    } else {
      return $this->_resolver_dns;
    }
  }
}
