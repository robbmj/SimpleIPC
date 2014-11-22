<?php namespace robbmj;

interface ChildWorker {
	function produce();
}


interface ParentWorker {
	function consume($input);
}

class IPC {
	private $p, $c;
	function __construct(ParentWorker $p, ChildWorker $c) {
		$this->p = $p;
		$this->c = $c;
	}

	function start() {
		$server_sock = dirname(__FILE__) . "/server.sock";
		$pid = pcntl_fork();
    	if ($pid === 0) {
    		try {
    			$this->childProcess($server_sock);	
    			exit(0);
    		}
    		catch (Exception $e) {
    			exit(1);
    		}
    	}
    	else if ($pid > 0) {
    		try {
    			$this->parentProcess($server_sock);
    			return true;
    		}
    		catch (Exception $e) {
    			return false;
    		}
    	}
    	else {
    		return false;
    	}
	}

	protected function childProcess($server_sock) {
		$produced = $this->c->produce();
		if (($client = socket_create(AF_UNIX, SOCK_STREAM, 0)) == false) {
            throw new Exception("failed to create socket: " . socket_strerror($client)); 
            
        }
   
        if (($ret = socket_connect($client, $server_sock)) != false) {
            $produced = ($produced) ? trim($produced) : '';

            var_dump(strlen($produced));
            while ((strlen($produced) > 0) && ($wrote = socket_write($client, $produced))) {
                $produced = substr($produced, $wrote);
            }

            socket_close($client);
            exit(0);
        }
        else {
            echo "failed to connect socket: " . socket_strerror($ret) . "\n"; 
            socket_close($client);
            exit(1);
        }
	}

	protected function parentProcess($server_sock) {
		if (($server = socket_create(AF_UNIX, SOCK_STREAM, 0)) == false) {
            throw new Exception("failed to create socket: " . socket_strerror($server));
        }

        if (($ret = socket_bind($server, $server_sock)) == false) {
        	unlink($server_sock);
       		socket_close($server);
            throw new Exception("failed to bind socket: " . socket_strerror($ret));
        }

        socket_listen($server);

        if (($client = socket_accept($server)) !== false) {
        	$content = '';
            while ($line = socket_read($client, 4098)) {
                $content .= $line;
            }
            socket_close($client);
        }

        unlink($server_sock);
        socket_close($server);
        
        if (isset($content)) {
        	$this->p->consume($content);
    	}
	}
}