<?php

require_once 'ipc.php';

class CurlWorker implements robbmj\ipc\ChildWorker {
	private $url;
	public function __construct($url) {
		$this->url = $url;
	}
	public function produce() {
		$ch = curl_init($this->url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $content = curl_exec($ch);
        curl_close($ch);
        return $content;
	}
}

class PWorker implements robbmj\ipc\ParentWorker {
	private $content = array();
	public function consume($input) {
		$this->content[] = $input;
	}
	public function getContent() {
		foreach ($this->content as $value) {
			$r[] = strlen($value);
		}
		return $r;
	}
}

$cWorkers = [new CurlWorker('https://www.google.ca/'), new CurlWorker('http://php.net/')];
$p = new PWorker();
$ipc = (new robbmj\ipc\IPC($p, $cWorkers))
		->maxWaitTime(10)
		->maxChildren(10);

$ipc->start();

var_dump($p->getContent());
