<?php
namespace SharkyDog\mDNS\Observer;
use SharkyDog\mDNS\Discoverer;

class Address extends Discoverer\Address {
  const S_ONLINE = 1;
  const S_EXPIRED = 2;
  const S_DETACHED = 4;

  const S_PENDING_NOTIFY = 256;

  public $status = 0;
  public $expire = 0;
}
