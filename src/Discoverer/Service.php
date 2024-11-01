<?php
namespace SharkyDog\mDNS\Discoverer;

class Service {
  public $name = '';
  public $priority = 0;
  public $weight = 0;
  public $port = 0;
  public $data = [];
  public $target = [];
}
