<?php 
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

namespace robbmj\ipc {

	interface ChildWorker {
		/**
		 * produce must return a string
		 */
		function produce();
	}

	interface ParentWorker {
		/**
		 * $input will always be a string
		 */
		function consume($input);
	}

	class IPC {
		private $pWorker, $cWorkers, $maxChildren, $maxWaitTime;
		
		function __construct(ParentWorker $pWorker, array /* ChildWorker */ $cWorkers) {
			$this->pWorker = $pWorker;
			$this->cWorkers = $cWorkers;
			$this->maxChildren = 0;
			$this->maxWaitTime = 0;
		}

		/**
		 * Sets the maximum number of Child Processes that can be running at any one time.
		 * If set to 0, There is no limit. 
		 *
		 * If $max is not a integer of is less than 0 an InvalidArgumentException is thrown  
		 */
		public function maxChildren($max) {
			if (!is_int($max) || $max < 0) {
				throw new \InvalidArgumentException("max must be greater than or equal to 0");
			}
			$this->maxChildren = $max;
			return $this;
		}

		/**
		 * Sets the maximum amount of time a child process can run for before the process is terminated.
		 * If set to 0, There is no limit. 
		 *
		 * If $seconds is not a integer of is less than 0 an InvalidArgumentException is thrown  
		 */
		public function maxWaitTime($seconds) {
			if (!is_int($seconds) || $seconds < 0) {
				throw new \InvalidArgumentException("seconds must be greater than or equal to 0");
			}
			$this->maxWaitTime = $seconds;
			return $this;
		}

		/**
		 * For each instance of ChildWorker a process will be started and ChildWorker::produce will be called,
		 * the return value will be passed to ParentWorker::consume() in the parent process.
		 */ 
		function start() {
			$pids = array();
			$sockets = array();
			
			foreach ($this->cWorkers as $i => $cWorker) {
				
				$socketPair = sp\SocketPair::create();
				if (!$socketPair) {
					continue;
				}

				$pid = pcntl_fork();
				if ($pid === 0) {
					$this->childProcess($socketPair, $cWorker);
					exit(0);
				}
				else if ($pid > 0) {
					$sockets[$pid] = $socketPair;
					if ($this->maxChildren > 0 && (count($sockets) >= $this->maxChildren)) {
						$this->reduceProcessCount($sockets, $this->maxChildren - 1);
					}
				}
			}
			$this->reduceProcessCount($sockets, 0);
		}

		protected function childProcess(sp\SocketPair $socketPair, ChildWorker $cWorker) {
			$output = $cWorker->produce();
			$socketPair->closeClient();
			socket_set_nonblock($socketPair->serverSock());
			$socketPair->write($output);
			$socketPair->closeServer();
		}

		protected function parentProcess(sp\SocketPair $socketPair) {
			$socketPair->closeServer();
			$content = $socketPair->read();
			$socketPair->closeClient();
			$this->pWorker->consume($content);
		}

		protected function reduceProcessCount(array &$sockets, $to) {
			while (count($sockets) > $to) {
				$pid = pcntl_wait($status, WNOHANG);
				if ($pid > 0) {
					$this->parentProcess($sockets[$pid]);
					unset($sockets[$pid]);	
				}
				else {
					$this->killExpiredProcesses($sockets);
					var_dump(count($sockets));
					usleep(200000);
				}
			}
		}

		protected function killExpiredProcesses(array &$sockets) {
			if ($this->maxWaitTime) {
				foreach ($sockets as $pid => $pair) {
					if ($pair->passedAllotedTime($this->maxWaitTime)) {
						$pair->closeServer();
						$pair->closeClient();
						unset($sockets[$pid]);
						// TODO: install a logger, echoing is not cool
						echo "PID: $pid took to long\n";
						posix_kill($pid, SIGINT);
					}	
				}
			}
		}
	}
} // namespace

namespace robbmj\ipc\sp {
	class SocketPair {
		private $clientSock, $serverSock, $createTime;

		function __construct($clientSock, $serverSock, $createTime = null) {
			$this->clientSock = $clientSock;
			$this->serverSock = $serverSock;
			$this->createTime = isset($createTime) ? $createTime : time();
		}

		function create() {
			$pair = array();
			if (socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair) === false) {
				// TODO: install a logger, echoing is not cool
				echo "socket_create_pair failed. Reason: " . socket_strerror(socket_last_error());
				return null;
			}
			return new SocketPair($pair[0], $pair[1]);
		}

		function passedAllotedTime($allotedTime) {
			return ($this->createTime + $allotedTime) <= time();
		}

		function clientSock() {
			return $this->clientSock;
		}

		function serverSock() {
			return $this->serverSock;
		}

		function closeClient() {
			socket_close($this->clientSock);
		}

		function closeServer() {
			socket_close($this->serverSock);
		}

		function write($content) {
			$written = 0;
			while ((strlen($content) > 0) && ($wrote = socket_write($this->serverSock(), $content))) {
				$content = substr($content, $wrote);
				$written += $wrote;
			}
			return $written;
		}

		function read() {
			$content = '';
			while ($line = socket_read($this->clientSock(), 1129)) {
				$len = strlen($content);
				$content .= $line;
			}
			return $content;
		}
	}
}