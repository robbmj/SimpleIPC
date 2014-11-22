<?php

require_once 'ipc.php';

class CWorker implements robbmj\ChildWorker {
	public function produce() {
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
	private $content;
	public function consume($input) {
		$this->content = $input;
	}
	public function getContent() {
		return $this->content;
	}
}

$c = new CWorker();
$p = new PWorker();
$ipc = new robbmj\IPC($p, $c);
$ipc->start();
var_dump(strlen($p->getContent()));
