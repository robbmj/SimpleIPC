<?php namespace robbmj;

interface ChildWorker {
	function produce();
}

interface ParentWorker {
	function consume($input);
}

class IPCException extends \Exception { }

class IPC {
	private $p, $c, $parentWaitTime, $childWaitTime;
	
	function __construct(ParentWorker $p, ChildWorker $c) {
		$this->p = $p;
		$this->c = $c;
		$this->parentWaitTime = 10;
		$this->childWaitTime = 10;
	}

	public function parentWaitTime($seconds) {
		if (!is_int($seconds) || $seconds < 1) {
			throw new \InvalidArgumentException("seconds must be greater than or equal to 1");
		}
		$this->parentWaitTime = $seconds;
		return $this;
	}

	public function childWaitTime($seconds) {
		if (!is_int($seconds) || $seconds < 1) {
			throw new \InvalidArgumentException("seconds must be greater than or equal to 1");
		}
		$this->childWaitTime = $seconds;
		return $this;
	}

	function start() {
		$server_sock = dirname(__FILE__) . "/server.sock";
		$pid = pcntl_fork();
    	if ($pid === 0) {
    		try {
    			register_shutdown_function(array($this, 'childShutdownHandler'));
    			$this->childProcess($server_sock);	
    			exit(0);
    		}
    		catch (IPCException $e) {
    			exit(1);
    		}
    	}
    	else if ($pid > 0) {
    		try {
    			$this->parentProcess($server_sock);
    			return true;
    		}
    		catch (IPCException $e) {
    			return false;
    		}
    	}
    	else {
    		return false;
    	}
	}

	protected function childShutdownHandler() {
		$e = error_get_last();
  		if ($e !== NULL) {
		    echo "Error of type: {$e['type']} msg: {$e['message']} in {$e['file']} on line {$e['line']}\n";
  		}
	}

	protected function childProcess($server_sock) {

		$produced = $this->c->produce();
		if (($client = socket_create(AF_UNIX, SOCK_STREAM, 0)) == false) {
            throw new IPCException("failed to create socket: " . socket_strerror($client)); 
        }

        $now = time();

        while (true) {
            if (($ret = socket_connect($client, $server_sock)) == false) {
            	if ($now + $this->childWaitTime < time()) {
                	throw new IPCException("Child process waited to long for connection from parent. Max wait time: {$this->childWaitTime}");
                }
                usleep(200000);
             }
             else {
                break;
             }
        }
   
        $produced = ($produced) ? trim($produced) : '';

        while ((strlen($produced) > 0) && ($wrote = socket_write($client, $produced))) {
            $produced = substr($produced, $wrote);
        }
        socket_close($client);
	}

	protected function parentProcess($server_sock) {

		if (($server = socket_create(AF_UNIX, SOCK_STREAM, 0)) == false) {
            throw new IPCException("failed to create socket: " . socket_strerror($server));
        }

        if (($ret = socket_bind($server, $server_sock)) == false) {
        	unlink($server_sock);
       		socket_close($server);
            throw new IPCException("failed to bind socket: " . socket_strerror($ret));
        }

        if (!socket_listen($server)) {
        	unlink($server_sock);
       		socket_close($server);
        	throw new IPCException("Failed to start socket listening on port: " . socket_strerror($ret));
        }

        if (!socket_set_nonblock($server)) {
        	unlink($server_sock);
       		socket_close($server);
        	throw new IPCException("Failed to set socket to non block: " . socket_strerror($ret));
        }

        $now = time();

		while (true) {    
            if (($client = socket_accept($server)) !== false) {
            	$content = '';
                echo "Client $client has connected\n";
                while ($line = socket_read($client, 4098)) {
                    $content .= $line;
                }
                socket_close($client);
                break;
            }
            else {
                if ($now + $this->parentWaitTime < time()) {
                    echo "Waited to long for client\n";
                    break;
                }
                usleep(200000);
            }
        }

        unlink($server_sock);
        socket_close($server);
        
        if (isset($content)) {
        	$this->p->consume($content);
    	}
	}
}