<?php
namespace SharkyDog\mDNS\Observer;
use SharkyDog\mDNS\Discoverer;

class Service extends Discoverer\Service {
  const S_ONLINE = 1;
  const S_EXPIRED = 2;
  const S_DETACHED = 4;
  const S_HAS_TXT = 8;
  const S_HAS_IP4 = 16;
  const S_HAS_IP6 = 32;

  const S_PENDING_NOTIFY = 256;

  public $status = 0;
  public $expire = 0;
}
