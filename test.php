<?php

require_once 'ipc.php';

class CurlWorker implements robbmj\ChildWorker {
	public function produce() {
		sleep(2);
		$ch = curl_init('https://www.google.ca/');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $content = curl_exec($ch);
        curl_close($ch);
        return $content;
	}
}

class PWorker implements robbmj\ParentWorker {
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

$cWorkers = [new CurlWorker(), new CurlWorker()];
$p = new PWorker();
$ipc = (new robbmj\IPC($p, $cWorkers))
		->maxWaitTime(0)
		->maxChildren(1);
		
$ipc->start();

var_dump($p->getContent());
