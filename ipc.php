<?php namespace robbmj;

/**
 * The MIT License (MIT)
 * 
 * Copyright (c) 2014 Michael John Robb
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

interface ChildWorker {
	/**
	 * produce must return a string
	 */
	function produce();
}

interface ParentWorker {
	function consume($input);
}

class IPCException extends \Exception { }

class IPC {
	private $pWorker, $cWorkers, $parentWaitTime, $childWaitTime;
	
	function __construct(ParentWorker $pWorker, array /* ChildWorker */ $cWorkers) {
		$this->pWorker = $pWorker;
		$this->cWorkers = $cWorkers;
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
		foreach ($this->cWorkers as $i => $cWorker) {
			$pid = pcntl_fork();
	    	if ($pid === 0) {
	    		try {
	    			register_shutdown_function(array($this, 'childShutdownHandler'));
	    			$this->childProcess($cWorker);	
	    			exit(0);
	    		}
	    		catch (IPCException $e) {
	    			exit(1);
	    		}
	    	}
	    	else if ($pid > 0) {
	    		try {
	    			$this->parentProcess($pid);
	    		}
	    		catch (IPCException $e) {
	    		}
	    	}
	    	else {
	    		return false;
	    	}
	    }
	}

	protected function childShutdownHandler() {
		$e = error_get_last();
  		if ($e !== NULL) {
		    echo "Error of type: {$e['type']} msg: {$e['message']} in {$e['file']} on line {$e['line']}\n";
  		}
	}

	protected function childProcess(ChildWorker $c) {
		$server_sock = dirname(__FILE__) . '/server-' . getmypid() . '.sock';
		$produced = $c->produce();
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

	protected function parentProcess($pid) {
		$server_sock = dirname(__FILE__) . '/server-' . $pid . '.sock';
		
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
        	$this->pWorker->consume($content);
    	}
	}
}